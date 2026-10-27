#!/usr/bin/env python3
"""vcsim SOAP gateway plus the appliance health REST API used by the template."""
import base64
from datetime import datetime, timezone
import hashlib
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import select
import socket
import threading
import time
import urllib.error
import urllib.request
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET

SCENARIOS = ('healthy', 'degraded', 'unresponsive')
SESSION = 'vmware-module-demo-session'
AUTH = 'Basic ' + base64.b64encode(b'mock:mock').decode()
VIM = 'urn:vim25'
SOAP = 'http://schemas.xmlsoap.org/soap/envelope/'
XSI = 'http://www.w3.org/2001/XMLSchema-instance'
ET.register_namespace('', VIM)
ET.register_namespace('soapenv', SOAP)
ET.register_namespace('xsi', XSI)


def xml(parent, name, value=None, **attributes):
    element = ET.SubElement(parent, '{' + VIM + '}' + name, attributes)
    if value is not None:
        element.text = str(value).lower() if isinstance(value, bool) else str(value)
    return element


def text(parent, path, default=''):
    return parent.findtext('/'.join('{*}' + part for part in path.split('/')), default)


class State:
    def __init__(self):
        self.scenario = 'healthy'
        self.changed = threading.Condition()
        self.changed_at = time.time()
        self.changed_tick = time.monotonic()
        self.degraded_seconds = 0

    def set(self, scenario):
        if scenario not in SCENARIOS:
            raise ValueError('Unknown scenario')
        with self.changed:
            if self.scenario != scenario:
                if self.scenario == 'degraded':
                    self.degraded_seconds += time.monotonic() - self.changed_tick
                self.changed_at = time.time()
                self.changed_tick = time.monotonic()
            self.scenario = scenario
            self.changed.notify_all()

    def port_state(self):
        with self.changed:
            elapsed = self.degraded_seconds
            if self.scenario == 'degraded':
                elapsed += time.monotonic() - self.changed_tick
            return self.scenario, self.changed_at, int(elapsed)


STATE = State()


class DvswitchFixture:
    """Model complete DVPorts from actual vcsim VM NIC backings; no monitoring DB writes."""
    def __init__(self):
        self.started = time.monotonic()
        self.lock = threading.Lock()
        self.cache = {}
        self.error = None

    @staticmethod
    def enabled():
        return os.getenv('VMWARE_MOCK_DVS_DETAILS', 'true').lower() == 'true'

    @staticmethod
    def request(upstream, headers, method, fill):
        envelope = ET.Element('{' + SOAP + '}Envelope', {'xmlns:vim': VIM})
        operation = xml(ET.SubElement(envelope, '{' + SOAP + '}Body'), method)
        fill(operation)
        connection = http.client.HTTPConnection(upstream.hostname, upstream.port or 80, timeout=3)
        try:
            connection.request('POST', '/sdk', ET.tostring(envelope, encoding='utf-8'), headers)
            response = connection.getresponse()
            body = ET.fromstring(response.read())
            if response.status != 200 or body.find('.//{*}Fault') is not None:
                raise ValueError('Simulator inventory request failed: ' + method)
            return body
        finally:
            connection.close()

    def inventory(self, upstream, headers):
        # Scope cached data to the upstream and authenticated SDK session. A new
        # simulator/session never reuses old managed-object IDs.
        session = tuple(sorted((k.lower(), v) for k, v in headers.items()
                               if k.lower() in ('cookie', 'authorization')))
        key = (upstream.netloc, session)
        with self.lock:
            entry = self.cache.get(key)
            if entry and time.monotonic() - entry[0] < 30:
                return entry[1]
            service = self.request(upstream, headers, 'RetrieveServiceContent',
                lambda op: xml(op, '_this', 'ServiceInstance', type='ServiceInstance'))
            content = service.find('.//{*}returnval')
            if content is None:
                raise ValueError('Simulator service content missing')
            def create(op):
                xml(op, '_this', text(content, 'viewManager'), type='ViewManager')
                xml(op, 'container', text(content, 'rootFolder'), type='Folder')
                xml(op, 'type', 'VirtualMachine')
                xml(op, 'type', 'DistributedVirtualSwitch')
                xml(op, 'type', 'DistributedVirtualPortgroup')
                xml(op, 'recursive', True)
            view = self.request(upstream, headers, 'CreateContainerView', create).find('.//{*}returnval')
            if view is None or not view.text:
                raise ValueError('Simulator inventory view missing')
            collector = text(content, 'propertyCollector')
            try:
                def retrieve(op):
                    xml(op, '_this', collector, type='PropertyCollector')
                    spec = xml(op, 'specSet')
                    for kind, paths in [('VirtualMachine', ['name', 'runtime.host', 'config.hardware.device']),
                                        ('DistributedVirtualSwitch', ['uuid', 'config.uplinkPortgroup']),
                                        ('DistributedVirtualPortgroup', ['name', 'config.distributedVirtualSwitch', 'config.uplink'])]:
                        props = xml(spec, 'propSet')
                        xml(props, 'type', kind)
                        for path in paths:
                            xml(props, 'pathSet', path)
                    objects = xml(spec, 'objectSet')
                    xml(objects, 'obj', view.text, type='ContainerView')
                    xml(objects, 'skip', True)
                    traversal = xml(objects, 'selectSet', **{'{' + XSI + '}type': 'vim:TraversalSpec'})
                    xml(traversal, 'name', 'fixtureView')
                    xml(traversal, 'type', 'ContainerView')
                    xml(traversal, 'path', 'view')
                    xml(traversal, 'skip', False)
                    xml(xml(op, 'options'), 'maxObjects', 100)
                page = self.request(upstream, headers, 'RetrievePropertiesEx', retrieve)
                objects = []
                for _ in range(100):
                    objects.extend(page.findall('.//{*}objects'))
                    token = page.findtext('.//{*}token')
                    if not token:
                        break
                    def more(op):
                        xml(op, '_this', collector, type='PropertyCollector')
                        xml(op, 'token', token)
                    page = self.request(upstream, headers, 'ContinueRetrievePropertiesEx', more)
                else:
                    raise ValueError('Simulator inventory pagination limit reached')
                result = self.decode_inventory(objects)
            finally:
                try:
                    self.request(upstream, headers, 'DestroyView',
                        lambda op: xml(op, '_this', view.text, type='ContainerView'))
                except (OSError, ValueError, http.client.HTTPException, ET.ParseError):
                    pass
            if len(self.cache) >= 8:
                self.cache.clear()
            self.cache[key] = (time.monotonic(), result)
            return result

    @staticmethod
    def decode_inventory(objects):
        nics, switches, uplink_groups = [], {}, []
        for obj in objects:
            ref = obj.find('{*}obj')
            if ref is None:
                continue
            props = {text(p, 'name'): p.find('{*}val') for p in obj.findall('{*}propSet')}
            if ref.get('type') in ('DistributedVirtualSwitch', 'VmwareDistributedVirtualSwitch'):
                uuid = props.get('uuid')
                uplinks = props.get('config.uplinkPortgroup')
                switches[ref.text] = {'uuid': uuid.text if uuid is not None else '',
                    'uplinks': [e.text for e in uplinks if e.text] if uplinks is not None else []}
            elif ref.get('type') == 'DistributedVirtualPortgroup':
                switch = props.get('config.distributedVirtualSwitch')
                name = props.get('name')
                uplink = props.get('config.uplink')
                # vcsim 0.55.2 omits config.uplink and the switch uplink array,
                # but names its real uplink group DVS*-DVUplinks-*.
                if switch is not None and ((uplink is not None and uplink.text == 'true')
                        or (name is not None and '-DVUplinks-' in (name.text or ''))):
                    uplink_groups.append((switch.text, ref.text))
            elif ref.get('type') == 'VirtualMachine':
                devices = props.get('config.hardware.device')
                host = props.get('runtime.host')
                name = props.get('name')
                for device in devices if devices is not None else []:
                    port = device.find('{*}backing/{*}port')
                    if port is None or not text(port, 'switchUuid'):
                        continue
                    nics.append({'vm': ref.text, 'name': name.text if name is not None else ref.text,
                        'host': host.text if host is not None else '', 'nic': text(device, 'key'),
                        'mac': text(device, 'macAddress'), 'uuid': text(port, 'switchUuid'),
                        'group': text(port, 'portgroupKey'), 'key': text(port, 'portKey'),
                        'connected': text(device, 'connectable/connected', 'true') == 'true'})
        for switch_id, group_id in uplink_groups:
            if switch_id in switches and group_id not in switches[switch_id]['uplinks']:
                switches[switch_id]['uplinks'].append(group_id)
        return {'nics': sorted(nics, key=lambda nic: (nic['vm'], nic['nic'])), 'switches': switches}

    def port(self, key, uuid, group, changed_at, ticks, fault_ticks, *, vm='', name='', host='',
             nic='', mac='', uplink=False, connected=False, failed=False, conflict_peer=''):
        port = ET.Element('{' + VIM + '}returnval')
        seed = int(hashlib.sha256((uuid + '/' + key).encode()).hexdigest()[:7], 16)
        xml(port, 'key', key)
        config = xml(port, 'config')
        xml(config, 'name', (name + ' NIC ' + nic) if vm else key)
        xml(config, 'description', 'Synthetic VMware module demo port')
        xml(config, 'configVersion', '1')
        xml(port, 'dvsUuid', uuid)
        xml(port, 'portgroupKey', group)
        if host:
            xml(port, 'proxyHost', host, type='HostSystem')
        if connected:
            connectee = xml(port, 'connectee')
            xml(connectee, 'connectedEntity', vm or host, type='VirtualMachine' if vm else 'HostSystem')
            xml(connectee, 'nicKey', nic)
            xml(connectee, 'type', 'vmVnic' if vm else 'pnic')
        xml(port, 'conflict', failed)
        if failed:
            xml(port, 'conflictPortKey', conflict_peer or key)
        runtime = xml(xml(port, 'state'), 'runtimeInfo')
        xml(runtime, 'linkUp', connected and not failed)
        xml(runtime, 'blocked', failed)
        vlan = xml(runtime, 'vlanIds')
        xml(vlan, 'start', 0 if uplink else 100)
        xml(vlan, 'end', 4094 if uplink else 100)
        xml(runtime, 'trunkingMode', uplink)
        xml(runtime, 'linkPeer', nic if connected else '')
        xml(runtime, 'macAddress', mac or '02:50:' + ':'.join(f'{(seed >> shift) & 255:02x}' for shift in (24, 16, 8, 0)))
        xml(runtime, 'statusDetail', 'Synthetic demo: port blocked, link down and conflict' if failed else 'Synthetic demo: normal')
        xml(runtime, 'vmDirectPathGen2Active', False)
        xml(runtime, 'vmDirectPathGen2InactiveReasonOther', 'portNptIncompatibleConnectee')
        stats = xml(port.find('{*}state'), 'stats')
        for direction in ('In', 'Out'):
            for kind, size in [('Unicast', 900), ('Multicast', 256), ('Broadcast', 128)]:
                packets = (seed % 100000 + 1000 + ticks * (100 if kind == 'Unicast' else 2)) if connected else 0
                xml(stats, 'packets' + direction + kind, packets)
                xml(stats, 'bytes' + direction + kind, packets * size)
            xml(stats, 'packets' + direction + 'Dropped', fault_ticks * 4)
            xml(stats, 'packets' + direction + 'Exception', fault_ticks)
        xml(port, 'connectionCookie', seed)
        xml(port, 'lastStatusChange', datetime.fromtimestamp(changed_at, timezone.utc).isoformat().replace('+00:00', 'Z'))
        xml(port, 'hostLocalPort', False)
        xml(port, 'externalId', 'vmware-module-demo/' + key)
        return port

    @staticmethod
    def matches(port, criteria, uplink):
        if criteria is None:
            return True
        for field, actual in [('connected', port.find('{*}connectee') is not None),
                              ('active', text(port, 'state/runtimeInfo/linkUp') == 'true'),
                              ('uplinkPort', uplink), ('nsxPort', False)]:
            value = text(criteria, field)
            if value and (value == 'true') != actual:
                return False
        keys = [e.text for e in criteria.findall('{*}portKey')]
        hosts = [e.text for e in criteria.findall('{*}host')]
        if keys and text(port, 'key') not in keys:
            return False
        if hosts and text(port, 'proxyHost') not in hosts:
            return False
        inside = text(criteria, 'inside')
        if inside:
            groups = [e.text for e in criteria.findall('{*}portgroupKey')]
            member = text(port, 'portgroupKey') in groups if groups else bool(text(port, 'portgroupKey'))
            if member != (inside == 'true'):
                return False
        return True

    def enrich(self, payload, request, inventory, scenario, changed_at, fault_ticks):
        root = ET.fromstring(payload)
        response = root.find('.//{*}FetchDVPortsResponse')
        if response is None or root.find('.//{*}Fault') is not None:
            return payload
        switch = inventory['switches'].get(text(request, '_this'))
        if not switch or not switch['uuid']:
            raise ValueError('FetchDVPorts switch missing from simulator inventory')
        uuid = switch['uuid']
        ticks = max(0, int(time.monotonic() - self.started))
        ports = []
        nics = [nic for nic in inventory['nics'] if nic['uuid'] == uuid]
        peer = nics[1]['key'] or nics[1]['vm'] + '-nic-' + nics[1]['nic'] if len(nics) > 1 else ''
        for index, nic in enumerate(nics):
            key = nic['key'] or nic['vm'] + '-nic-' + nic['nic']
            ports.append((self.port(key, uuid, nic['group'], changed_at, ticks, fault_ticks if index == 0 else 0,
                vm=nic['vm'], name=nic['name'], host=nic['host'], nic=nic['nic'], mac=nic['mac'],
                connected=nic['connected'], failed=scenario == 'degraded' and index == 0, conflict_peer=peer), False))
        hosts = sorted({nic['host'] for nic in nics if nic['host']})
        for host in hosts:
            if switch['uplinks']:
                ports.append((self.port('uplink-' + host, uuid, switch['uplinks'][0], changed_at, ticks, 0,
                    host=host, nic='vmnic0', uplink=True, connected=True), True))
        groups = sorted({text(p, 'portgroupKey') for p in response.findall('{*}returnval')} - {''})
        for group in groups:
            uplink = group in switch['uplinks']
            ports.append((self.port('spare-' + group, uuid, group, changed_at, ticks, 0, uplink=uplink), uplink))
        for node in response.findall('{*}returnval'):
            response.remove(node)
        criteria = request.find('{*}criteria')
        for port, uplink in ports:
            if self.matches(port, criteria, uplink):
                response.append(port)
        return ET.tostring(root, encoding='utf-8', xml_declaration=True)


DVS = DvswitchFixture()


class SimulatorInventory:
    """Read and seed native vcsim VM folders; forward native REST when requested."""
    prefix = '/rest/com/vmware/cis/tagging/'

    def __init__(self):
        self.url = os.environ.get('VMWARE_SIMULATOR_URL', 'http://simulator:8989').rstrip('/')
        self.headers = {'Content-Type': 'application/json'}
        self.sdk_cookie = None

    def rest(self, method, path, data=None):
        request = urllib.request.Request(self.url + path,
            None if data is None else json.dumps(data).encode(), self.headers, method=method)
        with urllib.request.urlopen(request, timeout=10) as response:
            body = response.read()
        return json.loads(body) if body else None

    def __enter__(self):
        self.headers['Authorization'] = AUTH
        session = self.rest('POST', '/rest/com/vmware/cis/session', {})['value']
        self.headers = {'Content-Type': 'application/json', 'vmware-api-session-id': session}
        return self

    def __exit__(self, *_):
        try:
            self.rest('DELETE', '/rest/com/vmware/cis/session')
        except (OSError, ValueError):
            pass

    def soap(self, method, fill):
        envelope = ET.Element('{' + SOAP + '}Envelope', {'xmlns:vim': VIM})
        operation = xml(ET.SubElement(envelope, '{' + SOAP + '}Body'), method)
        fill(operation)
        headers = {'Content-Type': 'text/xml', 'SOAPAction': 'urn:vim25/6.5'}
        if self.sdk_cookie:
            headers['Cookie'] = self.sdk_cookie
        request = urllib.request.Request(self.url + '/sdk', ET.tostring(envelope), headers)
        with urllib.request.urlopen(request, timeout=10) as response:
            if response.headers.get('Set-Cookie'):
                self.sdk_cookie = response.headers['Set-Cookie'].split(';', 1)[0]
            result = ET.fromstring(response.read())
        if result.find('.//{*}Fault') is not None:
            raise ValueError('Simulator SOAP request failed: ' + method)
        return result

    def wait_task(self, collector, task):
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            def retrieve(op):
                xml(op, '_this', collector, type='PropertyCollector')
                spec = xml(op, 'specSet')
                props = xml(spec, 'propSet')
                xml(props, 'type', 'Task')
                xml(props, 'pathSet', 'info.state')
                xml(props, 'pathSet', 'info.error')
                xml(xml(spec, 'objectSet'), 'obj', task, type='Task')
            result = self.soap('RetrieveProperties', retrieve)
            values = {text(prop, 'name'): text(prop, 'val') for prop in result.findall('.//{*}propSet')}
            if values.get('info.state') == 'success':
                return
            if values.get('info.state') == 'error':
                raise ValueError('Simulator VM folder move failed')
            time.sleep(.05)
        raise ValueError('Simulator VM folder move timed out')

    def seed_vm_folders(self, vms, folders, roots, collector):
        destinations = {}
        for root in roots:
            for name in ('FB-STP', 'FB-MSP', 'FB-OPS'):
                fid = next((fid for fid, folder in folders.items()
                            if folder['parent'] == root and folder['name'] == name), None)
                if fid is None:
                    def create(op):
                        xml(op, '_this', root, type='Folder')
                        xml(op, 'name', name)
                    fid = self.soap('CreateFolder', create).findtext('.//{*}returnval')
                    if not fid:
                        raise ValueError('Simulator did not create VM folder')
                    folders[fid] = {'name': name, 'parent': root}
                destinations[(root, name)] = fid
        for index, vm in enumerate(vms):
            # Seed only VMs still in the datacenter's default VM folder. Keep
            # user moves and all VM identities, tags and monitoring history.
            if vm['parent'] not in roots:
                continue
            name = 'FB-STP' if index < len(vms) / 2 else 'FB-MSP'
            destination = destinations[(vm['parent'], name)]
            def move(op):
                xml(op, '_this', destination, type='Folder')
                xml(op, 'list', vm['id'], type='VirtualMachine')
            task = self.soap('MoveIntoFolder_Task', move).findtext('.//{*}returnval')
            if not task:
                raise ValueError('Simulator did not start VM folder move')
            self.wait_task(collector, task)
            vm['parent'] = destination

    def vms(self, seed_folders=False):
        service = self.soap('RetrieveServiceContent',
            lambda op: xml(op, '_this', 'ServiceInstance', type='ServiceInstance')).find('.//{*}returnval')
        manager = text(service, 'sessionManager')
        def login(op):
            xml(op, '_this', manager, type='SessionManager')
            xml(op, 'userName', 'mock')
            xml(op, 'password', 'mock')
        self.soap('Login', login)
        view = None
        try:
            def create(op):
                xml(op, '_this', text(service, 'viewManager'), type='ViewManager')
                xml(op, 'container', text(service, 'rootFolder'), type='Folder')
                xml(op, 'type', 'VirtualMachine')
                xml(op, 'type', 'Folder')
                xml(op, 'type', 'Datacenter')
                xml(op, 'recursive', True)
            view = self.soap('CreateContainerView', create).find('.//{*}returnval')
            def retrieve(op):
                xml(op, '_this', text(service, 'propertyCollector'), type='PropertyCollector')
                spec = xml(op, 'specSet')
                for kind, paths in [('VirtualMachine', ['name', 'parent']), ('Folder', ['name', 'parent']),
                                    ('Datacenter', ['vmFolder'])]:
                    props = xml(spec, 'propSet')
                    xml(props, 'type', kind)
                    for path in paths:
                        xml(props, 'pathSet', path)
                objects = xml(spec, 'objectSet')
                xml(objects, 'obj', view.text, type='ContainerView')
                xml(objects, 'skip', True)
                traversal = xml(objects, 'selectSet', **{'{' + XSI + '}type': 'vim:TraversalSpec'})
                xml(traversal, 'type', 'ContainerView')
                xml(traversal, 'path', 'view')
                xml(traversal, 'skip', False)
            result = self.soap('RetrieveProperties', retrieve)
            vms, folders, roots = [], {}, set()
            for obj in result.findall('.//{*}returnval'):
                ref = obj.find('{*}obj')
                values = {text(p, 'name'): text(p, 'val') for p in obj.findall('{*}propSet')}
                if ref is None:
                    continue
                if ref.get('type') == 'VirtualMachine':
                    vms.append({'id': ref.text, 'name': values['name'], 'parent': values.get('parent', '')})
                elif ref.get('type') == 'Folder':
                    folders[ref.text] = {'name': values['name'], 'parent': values.get('parent', '')}
                elif ref.get('type') == 'Datacenter' and values.get('vmFolder'):
                    roots.add(values['vmFolder'])
            vms.sort(key=lambda vm: vm['name'])
            if seed_folders:
                self.seed_vm_folders(vms, folders, roots, text(service, 'propertyCollector'))
            for vm in vms:
                names, current, seen = [], vm.pop('parent'), set()
                while current in folders and current not in roots and current not in seen:
                    seen.add(current)
                    names.append(folders[current]['name'])
                    current = folders[current]['parent']
                vm['folder'] = '/'.join(reversed(names))
            return vms
        finally:
            try:
                if view is not None:
                    self.soap('DestroyView', lambda op: xml(op, '_this', view.text, type='ContainerView'))
            finally:
                self.soap('Logout', lambda op: xml(op, '_this', manager, type='SessionManager'))

class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *_args):
        pass

    def reply(self, status, value):
        body = json.dumps(value).encode()
        try:
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True

    def handle_request(self):
        path = urlsplit(self.path).path
        length = int(self.headers.get('Content-Length', 0))
        if length > 8388608:
            self.close_connection = True
            return self.reply(413, {'error': 'Request too large'})
        body = self.rfile.read(length)
        if path == '/healthz':
            return self.reply(200, {'status': 'ok'})
        if path.startswith('/_mock/'):
            if self.headers.get('Authorization') != AUTH:
                return self.reply(401, {'error': 'Basic mock:mock authentication required'})
            if path == '/_mock/inventory' and self.command in ('GET', 'POST'):
                try:
                    params = json.loads(body) if body else {}
                    if not isinstance(params, dict) or not isinstance(params.get('seed_folders', False), bool):
                        return self.reply(400, {'error': 'Expected a boolean seed_folders option'})
                    inventory = SimulatorInventory()
                    return self.reply(200, {'vms': inventory.vms(
                        seed_folders=self.command == 'POST' and params.get('seed_folders', False))})
                except (OSError, ValueError, ET.ParseError):
                    return self.reply(502, {'error': 'Simulator VM inventory unavailable'})
            if path == '/_mock/scenario' and self.command == 'POST':
                try:
                    STATE.set(json.loads(body)['scenario'])
                except (ValueError, KeyError, TypeError):
                    return self.reply(400, {'error': 'Expected scenario: ' + ', '.join(SCENARIOS)})
            elif path != '/_mock/status' or self.command != 'GET':
                return self.reply(404, {'error': 'Unknown control endpoint'})
            return self.reply(200, {'scenario': STATE.scenario, 'scenarios': SCENARIOS,
                'dvswitch_details': {'enabled': DVS.enabled(), 'synthetic_runtime': DVS.enabled(), 'error': DVS.error}})
        if STATE.scenario == 'unresponsive':
            # Keep controls available, and release workers when timed-out clients leave.
            with STATE.changed:
                while STATE.scenario == 'unresponsive':
                    readable, _, _ = select.select([self.connection], [], [], 0)
                    if readable:
                        try:
                            if not self.connection.recv(1, socket.MSG_PEEK):
                                return
                        except (ConnectionResetError, OSError):
                            return
                    STATE.changed.wait(.25)
            self.close_connection = True
            return
        if path.startswith('/sdk') or path == '/about':
            return self.forward(body)
        if path in ('/api/session', '/rest/com/vmware/cis/session'):
            if self.command == 'POST':
                if self.headers.get('Authorization') != AUTH:
                    return self.reply(401, {'error': 'Invalid credentials'})
                return self.reply(201, SESSION if path == '/api/session' else {'value': SESSION})
            if self.command == 'DELETE':
                return self.reply(200, None)
        authenticated = self.headers.get('vmware-api-session-id') == SESSION or (
            'vmware-api-session-id=' + SESSION in self.headers.get('Cookie', ''))
        if not authenticated:
            return self.reply(401, {'error': 'VMware API session required'})
        if path.startswith(SimulatorInventory.prefix):
            # Keep appliance-health sessions separate; tag data and assignments
            # live in vcsim, rather than an invented gateway inventory.
            try:
                data = json.loads(body) if body else None
                with SimulatorInventory() as inventory:
                    result = inventory.rest(self.command, self.path, data)
                return self.reply(200, result)
            except urllib.error.HTTPError as error:
                return self.reply(error.code, {'error': 'Simulator rejected tagging request'})
            except (OSError, ValueError):
                return self.reply(502, {'error': 'Simulator tagging API unavailable'})
        if path == '/rest/appliance/system/version':
            return self.reply(200, {'value': {'version': '8.0.3', 'product': 'VMware vCenter Server'}})
        if path in ('/api/appliance/health/system', '/rest/appliance/health/system'):
            health = 'green' if STATE.scenario == 'healthy' else 'red'
            return self.reply(200, health if path.startswith('/api/') else {'value': health})
        return self.reply(404, {'error': 'Endpoint is not simulated'})

    def forward(self, body):
        upstream = urlsplit(os.environ.get('VMWARE_SIMULATOR_URL', 'http://simulator:8989'))
        connection = http.client.HTTPConnection(upstream.hostname, upstream.port or 80, timeout=30)
        try:
            headers = {key: value for key, value in self.headers.items()
                       if key.lower() not in ('host', 'connection', 'content-length')}
            # vcsim exposes the base DVS type; Zabbix discovers VMware's subtype.
            # Adapt the SOAP type only, preserving simulator inventory and ports.
            body = body.replace(b'VmwareDistributedVirtualSwitch', b'DistributedVirtualSwitch')
            native_body = body
            fetch = None
            if DVS.enabled() and b'FetchDVPorts' in body:
                try:
                    document = ET.fromstring(body)
                except ET.ParseError:
                    document = None
                operation = document.find('.//{*}FetchDVPorts') if document is not None else None
                if operation is not None:
                    fetch = ET.fromstring(ET.tostring(operation))
                    # Apply criteria after modeling ports; vcsim cannot filter
                    # attachments or runtime fields that it does not populate.
                    criteria = operation.find('{*}criteria')
                    if criteria is not None:
                        operation.remove(criteria)
                    body = ET.tostring(document, encoding='utf-8')
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            payload = response.read()
            if fetch is not None and response.status == 200 and b'FetchDVPortsResponse' in payload:
                try:
                    inventory = DVS.inventory(upstream, headers)
                    scenario, changed_at, fault_ticks = STATE.port_state()
                    payload = DVS.enrich(payload, fetch, inventory, scenario, changed_at, fault_ticks)
                    DVS.error = None
                except (OSError, ValueError, http.client.HTTPException, ET.ParseError) as error:
                    # Preserve native simulator data on enrichment failure;
                    # diagnostics stay available without exposing SDK sessions.
                    DVS.error = type(error).__name__ + ': distributed-port enrichment unavailable'
                    if fetch.find('{*}criteria') is not None:
                        # Restore native filtering too, rather than returning
                        # the unfiltered response requested for enrichment.
                        connection.request(self.command, self.path, native_body, headers)
                        response = connection.getresponse()
                        payload = response.read()
            payload = payload.replace(b'type="DistributedVirtualSwitch"',
                                      b'type="VmwareDistributedVirtualSwitch"')
            # vcsim echoes wildcard series identifiers literally; Zabbix asks
            # for '*' but reads aggregate counters using the empty instance.
            # Preserve simulator samples while normalizing aggregate series.
            if b'QueryPerfResponse' in payload:
                payload = payload.replace(b'<instance>*</instance>', b'<instance></instance>')
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True
        except (OSError, http.client.HTTPException):
            self.close_connection = True
            self.reply(502, {'error': 'vSphere simulator is unavailable'})
        finally:
            connection.close()

    do_GET = handle_request
    do_POST = handle_request
    do_DELETE = handle_request


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
