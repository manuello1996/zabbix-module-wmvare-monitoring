#!/usr/bin/env python3
"""Drop-in/out VMware SOAP/REST demo, with an isolated Zabbix VMware proxy."""
import argparse
import base64
import getpass
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

HERE = Path(__file__).resolve().parent
STATE = HERE / '.demo-state.json'
OWNER = 'vmware-module-mock-v1'
HOST = 'vmware-module-demo'
PROXY = 'vmware-module-demo-proxy'
HOST_GROUP = 'VMware module demo'
TEMPLATE_GROUP = 'Templates/VMware module demo'
TEMPLATES = {name: 'DEMO - ' + name for name in ['VMware', 'VMware Hypervisor', 'VMware Guest']}
NAMESPACE = uuid.UUID('ad21c28a-f39c-46f9-94c8-55454ef53110')
MOCK_URL = 'http://vmware-module-mock:8080/sdk'


class Zabbix:
    def __init__(self, url, username, password=None, token=None):
        self.url = url.rstrip('/')
        self.url += '' if self.url.endswith('api_jsonrpc.php') else '/api_jsonrpc.php'
        self.token = token
        self.owns_session = not token
        version = self.call('apiinfo.version', {})
        if not version.startswith('7.0.'):
            raise RuntimeError(f'This demo targets Zabbix 7.0; endpoint reports {version}.')
        if not token:
            self.token = self.call('user.login', {'username': username, 'password': password})

    def call(self, method, params):
        payload = {'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}
        if self.token and method != 'apiinfo.version':
            payload['auth'] = self.token
        request = urllib.request.Request(self.url, json.dumps(payload).encode(),
                                         {'Content-Type': 'application/json-rpc'})
        result = json.load(urllib.request.urlopen(request, timeout=45))
        if 'error' in result:
            raise RuntimeError(f"{method}: {result['error'].get('data', result['error']['message'])}")
        return result['result']

    def close(self):
        if self.owns_session and self.token:
            self.call('user.logout', [])
            self.token = None


def compose(*arguments):
    subprocess.run(['docker', 'compose', '--project-name', 'vmware-module-mock',
                    '--file', str(HERE / 'compose.yaml'), *arguments], check=True)


def control(args, scenario=None):
    request = urllib.request.Request(args.mock_url.rstrip('/') + ('/_mock/scenario' if scenario else '/_mock/status'),
        json.dumps({'scenario': scenario}).encode() if scenario else None,
        {'Content-Type': 'application/json', 'Authorization': 'Basic ' + base64.b64encode(b'mock:mock').decode()})
    return json.load(urllib.request.urlopen(request, timeout=5))


def state_for(api):
    state = json.loads(STATE.read_text()) if STATE.exists() else {'owner': OWNER, 'api': api.url}
    if state.get('owner') != OWNER or state.get('api') != api.url:
        raise RuntimeError('State belongs to another fixture or Zabbix endpoint; refusing to modify it.')
    return state


def save(state):
    temp = STATE.with_suffix('.tmp')
    temp.write_text(json.dumps(state, indent=2) + '\n', encoding='utf-8')
    temp.replace(STATE)


def objects(api):
    hosts = api.call('host.get', {'output': ['hostid', 'host', 'proxyid'], 'filter': {'host': HOST},
        'selectTags': ['tag', 'value'], 'selectParentTemplates': ['templateid'], 'selectMacros': ['macro', 'value']})
    templates = api.call('template.get', {'output': ['templateid', 'host', 'description'],
        'filter': {'host': list(TEMPLATES.values())}})
    proxies = api.call('proxy.get', {'output': ['proxyid', 'name', 'description'], 'filter': {'name': PROXY}})
    for host in hosts:
        if {'tag': 'fixture', 'value': OWNER} not in host['tags']:
            raise RuntimeError('Existing demo host has no matching ownership tag.')
        macros = {m['macro']: m.get('value', '') for m in host.get('macros', [])}
        if macros.get('{$VMWARE.URL}') != MOCK_URL:
            raise RuntimeError('Demo host no longer points at this simulator; refusing to change/remove it.')
    for obj in templates + proxies:
        if not obj.get('description', '').startswith(OWNER + ':'):
            raise RuntimeError('An existing template/proxy has no matching ownership marker.')
    return (hosts[0] if hosts else None), templates, (proxies[0] if proxies else None)


def clone_export(export):
    """Clone the installed templates; preserve keys and discovery relationships."""
    def transform(value):
        if isinstance(value, dict):
            return {key: (uuid.UUID(bytes=uuid.uuid5(NAMESPACE, str(item)).bytes, version=4).hex
                    if key == 'uuid' else transform(item)) for key, item in value.items()}
        if isinstance(value, list):
            return [transform(item) for item in value]
        if isinstance(value, str):
            if value in TEMPLATES:
                return TEMPLATES[value]
            for original, cloned in TEMPLATES.items():
                value = value.replace('/' + original + '/', '/' + cloned + '/')
            return value
        return value
    cloned = transform(export)
    root = cloned['zabbix_export']
    root['template_groups'] = [{'name': TEMPLATE_GROUP,
        'uuid': uuid.UUID(bytes=uuid.uuid5(NAMESPACE, 'template-group').bytes, version=4).hex}]
    root['host_groups'] = [{'name': HOST_GROUP,
        'uuid': uuid.UUID(bytes=uuid.uuid5(NAMESPACE, 'host-group').bytes, version=4).hex}]
    for template in root['templates']:
        template['description'] = OWNER + ': isolated synthetic VMware demonstration.'
        template['groups'] = [{'name': TEMPLATE_GROUP}]
        for rule in template.get('discovery_rules', []):
            if rule.get('type') != 'DEPENDENT':
                rule['delay'] = '15s'
            for prototype in rule.get('host_prototypes', []):
                prototype['host'] = HOST + '-hv-{#HV.UUID}' if 'hv.discovery' in rule['key'] else HOST + '-vm-{#VM.UUID}'
                # Keep the actual VMware name so VM-to-hypervisor placement matches.
                prototype['group_links'] = [{'group': {'name': HOST_GROUP}}]
                prototype.pop('group_prototypes', None)
                prototype['tags'] = [{'tag': 'fixture', 'value': OWNER}, {'tag': 'environment', 'value': 'demo'}]
                prototype['discover'] = 'DISCOVER'
                prototype['inventory_mode'] = 'MANUAL'
            if rule.get('host_prototypes'):
                # The demo discovers all simulator VMs; production tag filters remain untouched.
                rule.pop('overrides', None)
            for item in rule.get('item_prototypes', []):
                if item.get('type') != 'DEPENDENT':
                    item['delay'] = '15s'
        for item in template.get('items', []):
            if item.get('type') != 'DEPENDENT':
                item['delay'] = '15s'
    return cloned


def ensure_group(api, state, kind, name):
    groups = api.call(kind + '.get', {'output': ['groupid'], 'filter': {'name': name}})
    if groups:
        return groups[0]['groupid']
    groupid = api.call(kind + '.create', {'name': name})['groupids'][0]
    state.setdefault('created_groups', []).append({'kind': kind, 'groupid': groupid})
    save(state)
    return groupid


def install(api, args):
    state = state_for(api)
    host, templates, proxy = objects(api)
    if templates and len(templates) != 3:
        raise RuntimeError('Partial template fixture found. Run down before retrying up.')
    groupid = ensure_group(api, state, 'hostgroup', HOST_GROUP)
    ensure_group(api, state, 'templategroup', TEMPLATE_GROUP)
    if not templates:
        originals = api.call('template.get', {'output': ['templateid', 'host'], 'filter': {'host': list(TEMPLATES)}})
        if len(originals) != 3:
            raise RuntimeError('Install the VMware, VMware Hypervisor and VMware Guest templates first.')
        exported = json.loads(api.call('configuration.export', {'format': 'json',
            'options': {'templates': [t['templateid'] for t in originals]}}))
        rules = {kind: {'createMissing': True, 'updateExisting': False} for kind in
            ['host_groups', 'template_groups', 'templates', 'items', 'discoveryRules', 'triggers', 'graphs', 'valueMaps']}
        rules['templateLinkage'] = {'createMissing': True}
        api.call('configuration.import', {'format': 'json', 'source': json.dumps(clone_export(exported)), 'rules': rules})
        host, templates, proxy = objects(api)
    if not proxy:
        proxyid = api.call('proxy.create', {'name': PROXY, 'operating_mode': 0,
            'description': OWNER + ': isolated VMware collectors; no server configuration changes.'})['proxyids'][0]
        state['proxyid'] = proxyid
        save(state)
    else:
        proxyid = proxy['proxyid']
    templateid = next(t['templateid'] for t in templates if t['host'] == TEMPLATES['VMware'])
    if host:
        if {t['templateid'] for t in host['parentTemplates']} != {templateid} or host['proxyid'] != proxyid:
            raise RuntimeError('Demo host has different template/proxy assignments; refusing to change it.')
    else:
        hostid = api.call('host.create', {'host': HOST, 'name': 'DEMO - VMware vCenter',
            'description': OWNER + ': removable SOAP/REST simulator.', 'monitored_by': 1, 'proxyid': proxyid,
            'groups': [{'groupid': groupid}], 'templates': [{'templateid': templateid}],
            'inventory_mode': 0, 'inventory': {'notes': 'Synthetic VMware vCenter infrastructure'},
            'tags': [{'tag': 'fixture', 'value': OWNER}, {'tag': 'environment', 'value': 'demo'}],
            'macros': [{'macro': '{$VMWARE.URL}', 'value': MOCK_URL},
                {'macro': '{$VMWARE.USERNAME}', 'value': 'mock'},
                {'macro': '{$VMWARE.PASSWORD}', 'value': 'mock', 'type': 1},
                {'macro': '{$VMWARE.VM.POWERSTATE}', 'value': '.*'},
                {'macro': '{$VMWARE.HV.SENSOR.DISCOVERY}', 'value': 'true'},
                {'macro': '{$VMWARE.LOCATION}', 'value': 'Demo, VMware simulator'}]})['hostids'][0]
        state['hostid'] = hostid
        save(state)
    state['hostid'] = (host or {}).get('hostid', state.get('hostid'))
    save(state)
    compose('up', '-d', '--build')
    for attempt in range(30):
        try:
            control(args, args.scenario)
            break
        except OSError:
            if attempt == 29:
                raise RuntimeError('Mock gateway did not become ready.')
            time.sleep(1)
    print('Demo installed. Allow 2-5 minutes for proxy configuration, SOAP collection and discovery.')
    print(args.zabbix_url.rstrip('/') + '/zabbix.php?action=vmware.monitoring.view&force_vcenter_refresh=1&filter_set=1&filter_hostid[0]=' + state['hostid'])


def status(api, args):
    host, templates, proxy = objects(api)
    print('Host:', host['hostid'] if host else 'absent', '| Templates:', len(templates), '| Proxy:', bool(proxy))
    try:
        print('Mock:', json.dumps(control(args)))
    except OSError as error:
        print('Mock unavailable:', error)
    if host:
        rules = api.call('discoveryrule.get', {'output': ['key_', 'state', 'error'], 'hostids': [host['hostid']]})
        items = api.call('item.get', {'output': ['key_', 'state', 'error', 'lastclock'], 'hostids': [host['hostid']]})
        print('vCenter items:', len(items), '| With samples:', sum(int(i['lastclock']) > 0 for i in items))
        children = api.call('host.get', {'output': ['hostid', 'name'], 'tags': [{'tag': 'fixture', 'value': OWNER}]})
        print('Fixture hosts:', len(children))
        for item in [i for i in items + rules if i.get('state') == '1'][:12]:
            print(' ', item['key_'] + ': ' + item['error'])


def uninstall(api, args):
    state = state_for(api)
    host, templates, proxy = objects(api)
    # Verify every consumer before deleting templates or stopping its proxy.
    consumers = api.call('host.get', {'output': ['hostid', 'host'],
        'templateids': [t['templateid'] for t in templates], 'selectTags': ['tag', 'value']}) if templates else []
    for consumer in consumers:
        if not consumer['host'].startswith(HOST) or {'tag': 'fixture', 'value': OWNER} not in consumer['tags']:
            raise RuntimeError('A demo template has a non-fixture consumer; refusing removal.')
    if templates:
        children = api.call('template.get', {'output': ['templateid'], 'parentTemplateids': [t['templateid'] for t in templates]})
        if children:
            raise RuntimeError('A demo template has additional template consumers; refusing removal.')
    if proxy:
        monitored = api.call('host.get', {'output': ['hostid', 'host'], 'proxyids': [proxy['proxyid']]})
        owned_ids = {h['hostid'] for h in consumers} | ({host['hostid']} if host else set())
        if any(h['hostid'] not in owned_ids for h in monitored):
            raise RuntimeError('The demo proxy monitors another host; refusing removal.')
    if host:
        api.call('host.delete', [host['hostid']])
    if templates:
        # Deleting the isolated host prototypes also removes their discovered hosts.
        api.call('template.delete', [t['templateid'] for t in templates])
    if proxy:
        api.call('proxy.delete', [proxy['proxyid']])
    for group in state.get('created_groups', []):
        kind, groupid = group['kind'], group['groupid']
        members = api.call(('host' if kind == 'hostgroup' else 'template') + '.get',
            {'output': ['hostid' if kind == 'hostgroup' else 'templateid'], 'groupids': [groupid]})
        if not members and api.call(kind + '.get', {'output': ['groupid'], 'groupids': [groupid]}):
            api.call(kind + '.delete', [groupid])
    compose('down')
    STATE.unlink(missing_ok=True)
    print('Removed the owned demo, discovered objects, templates, proxy and empty groups.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['up', 'down', 'status', 'scenario'])
    parser.add_argument('--scenario', choices=['healthy', 'degraded', 'unresponsive'], default='healthy')
    parser.add_argument('--zabbix-url', default=os.getenv('ZABBIX_URL', 'http://127.0.0.1'))
    parser.add_argument('--user', default=os.getenv('ZABBIX_USER', 'Admin'))
    parser.add_argument('--mock-url', default='http://127.0.0.1:18989')
    args = parser.parse_args()
    if args.command == 'scenario':
        print(json.dumps(control(args, args.scenario)))
        return
    token = os.getenv('ZABBIX_API_TOKEN')
    api = Zabbix(args.zabbix_url, args.user, None if token else os.getenv('ZABBIX_PASSWORD') or getpass.getpass('Zabbix password: '), token)
    try:
        {'up': install, 'down': uninstall, 'status': status}[args.command](api, args)
    finally:
        api.close()


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        print('Error:', error, file=sys.stderr)
        sys.exit(1)

