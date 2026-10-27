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
TEAM_MACRO = '{#VM.TEAM}'
TEAM_GROUP = HOST_GROUP + '/Team/' + TEAM_MACRO
GROUP_MACRO = '{#VM.GROUP}'
DEFAULT_TEAM_GROUPS = {'Linux': 'LXP'}
TEAM_PREPROCESSING = '''// vmware-module-demo: vCenter Team tag
var groupMap = JSON.parse(__TEAM_GROUP_MAP__);
var payload = JSON.parse(value);
var rows = Array.isArray(payload) ? payload : payload.data;
if (!Array.isArray(rows)) {
    throw 'Expected a VMware VM discovery array';
}
for (var i = 0; i < rows.length; i++) {
    var names = [];
    var tags = rows[i].tags || [];
    for (var j = 0; j < tags.length; j++) {
        if (tags[j].category === 'Team' && typeof tags[j].name === 'string') {
            var name = tags[j].name.replace(/^\\s+|\\s+$/g, '');
            if (name !== '') names.push(name);
        }
    }
    names.sort();
    rows[i].vm_team = names.length ? names[0] : 'Unassigned';
    rows[i].vm_group = Object.prototype.hasOwnProperty.call(groupMap, rows[i].vm_team)
        ? groupMap[rows[i].vm_team] : 'VMware module demo/Team/' + rows[i].vm_team;
}
return JSON.stringify(payload);'''
VM_TAGS_KEY = 'vmware.vm.tags.get[{$VMWARE.URL},{$VMWARE.VM.UUID}]'


def team_preprocessing(team_groups=None):
    mapping = DEFAULT_TEAM_GROUPS if team_groups is None else team_groups
    encoded = json.dumps(json.dumps(mapping, ensure_ascii=True, sort_keys=True), ensure_ascii=True)
    return TEAM_PREPROCESSING.replace('__TEAM_GROUP_MAP__', encoded)


def team_group_mapping(existing=None, arguments=()):
    mapping = dict(DEFAULT_TEAM_GROUPS if existing is None else existing)
    for argument in arguments:
        team, separator, group = argument.partition('=')
        team, group = team.strip(), group.strip()
        if not separator or not team or not group or group.startswith('/') or group.endswith('/') or '//' in group:
            raise ValueError('Team group mapping must be TEAM=GROUP with a valid nonempty group name.')
        mapping[team] = group
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


def simulator_tags(args, seed=False):
    request = urllib.request.Request(args.mock_url.rstrip('/') + '/_mock/tags',
        b'{}' if seed else None, {'Content-Type': 'application/json',
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


def clone_export(export, team_groups=None):
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
            if rule['key'].startswith('vmware.vm.discovery['):
                rule.setdefault('preprocessing', []).append(
                    {'type': 'JAVASCRIPT', 'parameters': [team_preprocessing(team_groups)]})
                paths = rule.setdefault('lld_macro_paths', [])
                paths[:] = [p for p in paths if p['lld_macro'] not in (TEAM_MACRO, GROUP_MACRO)]
                paths.append({'lld_macro': TEAM_MACRO, 'path': '$.vm_team'})
                paths.append({'lld_macro': GROUP_MACRO, 'path': '$.vm_group'})
                for path in paths:
                    if path['lld_macro'] == '{#VM.TAGS}':
                        path['path'] = '$.tags[*].name'
                for prototype in rule.get('host_prototypes', []):
                    prototype['tags'].append({'tag': 'Team', 'value': TEAM_MACRO})
                    prototype['group_prototypes'] = [{'name': GROUP_MACRO}]
            for item in rule.get('item_prototypes', []):
                if item.get('type') != 'DEPENDENT':
                    item['delay'] = '15s'
        for item in template.get('items', []):
            if item.get('type') != 'DEPENDENT':
                item['delay'] = '15s'
    return cloned


def configure_vm_tags(api, templates, team_groups=None):
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
    steps = [step for step in rule['preprocessing']
             if not step['params'].startswith('// vmware-module-demo: vCenter Team tag')]
    steps.append({'type': 21, 'params': team_preprocessing(team_groups), 'error_handler': 0, 'error_handler_params': ''})
    paths = [p for p in rule['lld_macro_paths'] if p['lld_macro'] not in (TEAM_MACRO, GROUP_MACRO)]
    paths.append({'lld_macro': TEAM_MACRO, 'path': '$.vm_team'})
    paths.append({'lld_macro': GROUP_MACRO, 'path': '$.vm_group'})
    for path in paths:
        if path['lld_macro'] == '{#VM.TAGS}':
            path['path'] = '$.tags[*].name'
    api.call('discoveryrule.update', {'itemid': rule['itemid'], 'preprocessing': steps,
                                    'lld_macro_paths': paths})
    for prototype in prototypes:
        tags = [t for t in prototype['tags'] if t['tag'] != 'Team']
        tags.append({'tag': 'Team', 'value': TEAM_MACRO})
        groups = [{'group_prototypeid': g['group_prototypeid'], 'name': g['name']}
                  for g in prototype['groupPrototypes']]
        for group in groups:
            if group['name'] == TEAM_GROUP:
                group['name'] = GROUP_MACRO
        if not any(g['name'] == GROUP_MACRO for g in groups):
            groups.append({'name': GROUP_MACRO})
        api.call('hostprototype.update', {'hostid': prototype['hostid'], 'tags': tags, 'groupPrototypes': groups})
    guestid = next(t['templateid'] for t in templates if t['host'] == TEMPLATES['VMware Guest'])
    if not api.call('item.get', {'output': ['itemid'], 'hostids': [guestid], 'filter': {'key_': VM_TAGS_KEY}}):
        api.call('item.create', {'hostid': guestid, 'name': 'VMware tags', 'key_': VM_TAGS_KEY,
            'type': 3, 'value_type': 4, 'delay': '15s', 'history': '7d',
            'username': '{$VMWARE.USERNAME}', 'password': '{$VMWARE.PASSWORD}',
            'description': 'Native vCenter tags, including their categories. Team is also mapped to a host tag and group.'})


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
    team_groups = team_group_mapping(state.get('team_groups'), getattr(args, 'team_group', None) or [])
    conflicts = api.call('hostgroup.get', {'output': ['name', 'flags'],
        'filter': {'name': list(team_groups.values())}}) if team_groups else []
    if any(group['flags'] == '0' for group in conflicts):
        raise RuntimeError('Mapped groups already exist as manual groups. Zabbix 7.0 group prototypes can only '
                           'reuse discovered groups; choose a new/discovered group name: '
                           + ', '.join(g['name'] for g in conflicts if g['flags'] == '0'))
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
        api.call('configuration.import', {'format': 'json', 'source': json.dumps(clone_export(exported, team_groups)), 'rules': rules})
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
    vms = simulator_tags(args, seed=True)
    if not vms or any(not any(t['category'] == 'Team' for t in vm['tags']) for vm in vms):
        raise RuntimeError('Simulator VMs are missing their native Team tags.')
    configure_vm_tags(api, templates, team_groups)
    state['team_groups'] = team_groups
    save(state)
    print('Team to host group mapping:', json.dumps(team_groups))
    print('Native simulator Team tags:', json.dumps(vms))
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
        print('Native simulator VM tags:', json.dumps(simulator_tags(args)))
        vm_hosts = api.call('host.get', {'output': ['hostid', 'host', 'name'],
            'tags': [{'tag': 'fixture', 'value': OWNER}], 'selectTags': 'extend',
            'selectHostGroups': ['name']})
        for vm in vm_hosts:
            if vm['host'].startswith(HOST + '-vm-'):
                print(' ', vm['name'], '| Team:', ', '.join(t['value'] for t in vm['tags'] if t['tag'] == 'Team'),
                    '| Groups:', ', '.join(g['name'] for g in vm['hostgroups']))
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
    parser.add_argument('--team-group', action='append', metavar='TEAM=GROUP',
        help='Map a vCenter Team value to a discovered group name; repeat for multiple teams. Saved for later up runs.')
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

