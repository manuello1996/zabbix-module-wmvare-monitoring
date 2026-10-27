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
FOLDER_GROUP_MACRO = '{#VM.FOLDER.GROUP}'
FOLDER_MAP_MACRO = '{$VMWARE.VM.FOLDER.GROUPS}'
FOLDER_MAP_REFERENCE = '{{$VMWARE.VM.FOLDER.GROUPS}.urlencode()}'
FOLDER_MAP_DESCRIPTION = 'JSON object mapping main vCenter folder names to discovered host groups; subfolders are ignored.'
DEFAULT_FOLDER_GROUPS = {'FB-STP': 'STP', 'FB-MSP': 'MSP', 'FB-OPS': 'RUN'}
DEFAULT_GROUP_MACRO = '{$VMWARE.VM.FOLDER.GROUP.DEFAULT}'
DEFAULT_GROUP_REFERENCE = '{{$VMWARE.VM.FOLDER.GROUP.DEFAULT}.urlencode()}'
DEFAULT_UNMAPPED_GROUP = HOST_GROUP + '/Unmapped folders'
LEGACY_LLD_MACROS = {'{#VM.TEAM}', '{#VM.GROUP}'}
LEGACY_GROUP_PROTOTYPES = {'{#VM.GROUP}', HOST_GROUP + '/Team/{#VM.TEAM}'}
FOLDER_PREPROCESSING = '''// vmware-module-demo: folder mapping
var folderMap;
try {
    folderMap = JSON.parse(decodeURIComponent('{{$VMWARE.VM.FOLDER.GROUPS}.urlencode()}'));
}
catch (error) {
    throw 'Invalid folder mapping macro: expected a JSON object of folder-to-group names';
}
if (!folderMap || typeof folderMap !== 'object' || Array.isArray(folderMap)) {
    throw 'Invalid folder mapping macro: expected a JSON object of folder-to-group names';
}
Object.keys(folderMap).forEach(function (folder) {
    var group = folderMap[folder];
    if (!folder || typeof group !== 'string' || !group || group !== group.replace(/^\\s+|\\s+$/g, '')
            || group.charAt(0) === '/' || group.charAt(group.length - 1) === '/' || group.indexOf('//') !== -1) {
        throw 'Invalid folder mapping macro: folder and group names must be nonempty valid strings';
    }
});
var defaultGroup = decodeURIComponent('{{$VMWARE.VM.FOLDER.GROUP.DEFAULT}.urlencode()}');
if (!defaultGroup || defaultGroup !== defaultGroup.replace(/^\\s+|\\s+$/g, '')
        || defaultGroup.charAt(0) === '/' || defaultGroup.charAt(defaultGroup.length - 1) === '/'
        || defaultGroup.indexOf('//') !== -1) {
    throw 'Invalid default folder group macro: expected a nonempty valid host group name';
}
var payload = JSON.parse(value);
var rows = Array.isArray(payload) ? payload : payload.data;
if (!Array.isArray(rows)) {
    throw 'Expected a VMware VM discovery array';
}
for (var i = 0; i < rows.length; i++) {
    var folder = rows[i]['{#VM.FOLDER}'] || '';
    var mainFolder = folder.split('/')[0];
    rows[i].vm_folder_group = defaultGroup;
    if (mainFolder !== 'vm' && Object.prototype.hasOwnProperty.call(folderMap, mainFolder)) {
        rows[i].vm_folder_group = folderMap[mainFolder];
    }
}
return JSON.stringify(payload);'''


def folder_preprocessing():
    return FOLDER_PREPROCESSING


def parse_default_group(value):
    if (not isinstance(value, str) or not value or value != value.strip()
            or value.startswith('/') or value.endswith('/') or '//' in value):
        raise ValueError(DEFAULT_GROUP_MACRO + ' must be a nonempty valid host group name.')
    return value


def parse_folder_mapping(value):
    try:
        mapping = json.loads(value)
    except (ValueError, TypeError) as error:
        raise ValueError(FOLDER_MAP_MACRO + ' must be a JSON object of folder-to-group names.') from error
    if not isinstance(mapping, dict):
        raise ValueError(FOLDER_MAP_MACRO + ' must be a JSON object of folder-to-group names.')
    for folder, group in mapping.items():
        if (not folder or not isinstance(group, str) or not group or group != group.strip()
                or group.startswith('/') or group.endswith('/') or '//' in group):
            raise ValueError(FOLDER_MAP_MACRO + ' requires nonempty folders and valid nonempty group names.')
    return mapping


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


def simulator_inventory(args, seed=False):
    request = urllib.request.Request(args.mock_url.rstrip('/') + '/_mock/inventory',
        json.dumps({'seed_folders': True}).encode() if seed else None, {'Content-Type': 'application/json',
        'Authorization': 'Basic ' + base64.b64encode(b'mock:mock').decode()})
    return json.load(urllib.request.urlopen(request, timeout=45))['vms']


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


def clone_export(export, folder_groups=None):
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
        if template['template'] == TEMPLATES['VMware']:
            template.setdefault('macros', []).extend([{'macro': FOLDER_MAP_MACRO,
                'value': json.dumps(DEFAULT_FOLDER_GROUPS if folder_groups is None else folder_groups),
                'description': FOLDER_MAP_DESCRIPTION},
                {'macro': DEFAULT_GROUP_MACRO, 'value': DEFAULT_UNMAPPED_GROUP,
                 'description': 'Discovered host group for VMs whose vCenter folder has no matching mapping.'}])
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
            if rule['key'].startswith('vmware.vm.discovery['):
                rule.setdefault('preprocessing', []).append(
                    {'type': 'JAVASCRIPT', 'parameters': [folder_preprocessing()]})
                paths = rule.setdefault('lld_macro_paths', [])
                paths[:] = [p for p in paths if p['lld_macro'] not in LEGACY_LLD_MACROS | {FOLDER_GROUP_MACRO}]
                paths.append({'lld_macro': FOLDER_GROUP_MACRO, 'path': '$.vm_folder_group'})
                for prototype in rule.get('host_prototypes', []):
                    prototype['group_prototypes'] = [{'name': FOLDER_GROUP_MACRO}]
            for item in rule.get('item_prototypes', []):
                if item.get('type') != 'DEPENDENT':
                    item['delay'] = '15s'
        for item in template.get('items', []):
            if item.get('type') != 'DEPENDENT':
                item['delay'] = '15s'
    return cloned


def configure_vm_folders(api, templates, initial_mapping=None):
    """Migrate only the owned demo rules/prototypes, preserving other settings."""
    templateid = next(t['templateid'] for t in templates if t['host'] == TEMPLATES['VMware'])
    rules = api.call('discoveryrule.get', {'output': ['itemid', 'key_'], 'hostids': [templateid],
        'selectLLDMacroPaths': 'extend', 'selectPreprocessing': 'extend'})
    rule = next(r for r in rules if r['key_'].startswith('vmware.vm.discovery['))
    prototypes = api.call('hostprototype.get', {'output': ['hostid', 'host'],
        'discoveryids': [rule['itemid']], 'selectTags': 'extend', 'selectGroupPrototypes': 'extend'})
    for prototype in prototypes:
        if not prototype['host'].startswith(HOST + '-vm-') or (
                {'tag': 'fixture', 'value': OWNER} not in prototype['tags']):
            raise RuntimeError('VM prototype is not owned by the demo; refusing to modify it.')
    mappings = api.call('usermacro.get', {'output': ['hostmacroid', 'description'], 'hostids': [templateid],
                                         'filter': {'macro': FOLDER_MAP_MACRO}})
    if not mappings:
        api.call('usermacro.create', {'hostid': templateid, 'macro': FOLDER_MAP_MACRO,
            'value': json.dumps(DEFAULT_FOLDER_GROUPS if initial_mapping is None else initial_mapping),
            'description': FOLDER_MAP_DESCRIPTION})
    elif mappings[0].get('description') == 'JSON object mapping vCenter folder names or paths to discovered host groups.':
        api.call('usermacro.update', {'hostmacroid': mappings[0]['hostmacroid'], 'description': FOLDER_MAP_DESCRIPTION})
    if not api.call('usermacro.get', {'output': ['hostmacroid'], 'hostids': [templateid],
                                     'filter': {'macro': DEFAULT_GROUP_MACRO}}):
        api.call('usermacro.create', {'hostid': templateid, 'macro': DEFAULT_GROUP_MACRO,
            'value': DEFAULT_UNMAPPED_GROUP,
            'description': 'Discovered host group for VMs whose vCenter folder has no matching mapping.'})
    steps = [step for step in rule['preprocessing'] if not step['params'].startswith(
        ('// vmware-module-demo: vCenter Team tag', '// vmware-module-demo: folder mapping'))]
    steps.append({'type': 21, 'params': folder_preprocessing(), 'error_handler': 0, 'error_handler_params': ''})
    paths = [p for p in rule['lld_macro_paths'] if p['lld_macro'] not in LEGACY_LLD_MACROS | {FOLDER_GROUP_MACRO}]
    paths.append({'lld_macro': FOLDER_GROUP_MACRO, 'path': '$.vm_folder_group'})
    api.call('discoveryrule.update', {'itemid': rule['itemid'], 'preprocessing': steps,
                                    'lld_macro_paths': paths})
    for prototype in prototypes:
        tags = [t for t in prototype['tags'] if not (t['tag'] == 'Team' and t['value'] == '{#VM.TEAM}')]
        groups = [{'group_prototypeid': g['group_prototypeid'], 'name': g['name']}
                  for g in prototype['groupPrototypes'] if g['name'] not in LEGACY_GROUP_PROTOTYPES]
        if not any(g['name'] == FOLDER_GROUP_MACRO for g in groups):
            groups.append({'name': FOLDER_GROUP_MACRO})
        api.call('hostprototype.update', {'hostid': prototype['hostid'], 'tags': tags, 'groupPrototypes': groups})
    guestid = next(t['templateid'] for t in templates if t['host'] == TEMPLATES['VMware Guest'])
    legacy = api.call('item.get', {'output': ['itemid', 'description'], 'hostids': [guestid],
        'filter': {'key_': 'vmware.vm.tags.get[{$VMWARE.URL},{$VMWARE.VM.UUID}]'}})
    owned_items = [item['itemid'] for item in legacy if 'Team is also mapped' in item.get('description', '')]
    if owned_items:
        api.call('item.delete', owned_items)


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
    folder_groups = dict(DEFAULT_FOLDER_GROUPS, **state.get('folder_groups', {}))
    default_group = DEFAULT_UNMAPPED_GROUP
    if templates and len(templates) != 3:
        raise RuntimeError('Partial template fixture found. Run down before retrying up.')
    if templates:
        templateid = next(t['templateid'] for t in templates if t['host'] == TEMPLATES['VMware'])
        macros = {m['macro']: m['value'] for m in api.call('usermacro.get',
            {'output': ['macro', 'value'], 'hostids': [templateid],
             'filter': {'macro': [FOLDER_MAP_MACRO, DEFAULT_GROUP_MACRO]}})}
        if FOLDER_MAP_MACRO in macros:
            folder_groups = parse_folder_mapping(macros[FOLDER_MAP_MACRO])
        if DEFAULT_GROUP_MACRO in macros:
            default_group = parse_default_group(macros[DEFAULT_GROUP_MACRO])
    destinations = set(folder_groups.values()) | {default_group}
    conflicts = api.call('hostgroup.get', {'output': ['name', 'flags'],
        'filter': {'name': sorted(destinations)}}) if destinations else []
    if any(group['flags'] == '0' for group in conflicts):
        raise RuntimeError('Mapped groups already exist as manual groups. Zabbix 7.0 group prototypes can only '
                           'reuse discovered groups; choose a new/discovered group name: '
                           + ', '.join(g['name'] for g in conflicts if g['flags'] == '0'))
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
        api.call('configuration.import', {'format': 'json', 'source': json.dumps(clone_export(exported, folder_groups)), 'rules': rules})
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
        linked = {t['templateid'] for t in host['parentTemplates']}
        # The module's opt-in companion may be linked manually to the demo.
        optional = api.call('template.get', {'output': ['templateid'],
            'filter': {'host': 'VMware Distributed Switches'}}) if linked != {templateid} else []
        allowed = {templateid} | {t['templateid'] for t in optional}
        if templateid not in linked or not linked <= allowed or host['proxyid'] != proxyid:
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
    vms = simulator_inventory(args, seed=True)
    if not vms:
        raise RuntimeError('Simulator inventory has no VMs.')
    configure_vm_folders(api, templates, folder_groups)
    state.pop('team_groups', None)
    state.pop('folder_groups', None)
    save(state)
    print('Folder mapping template macro:', FOLDER_MAP_MACRO, '=', json.dumps(folder_groups))
    print('Default group template macro:', DEFAULT_GROUP_MACRO, '=', default_group)
    print('Native simulator VM folders:', json.dumps(vms))
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
        print('Native simulator VM folders:', json.dumps(simulator_inventory(args)))
        vm_hosts = api.call('host.get', {'output': ['hostid', 'host', 'name'],
            'tags': [{'tag': 'fixture', 'value': OWNER}], 'selectTags': 'extend',
            'selectHostGroups': ['name']})
        for vm in vm_hosts:
            if vm['host'].startswith(HOST + '-vm-'):
                print(' ', vm['name'], '| Groups:', ', '.join(g['name'] for g in vm['hostgroups']))
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

