"""Folder-only discovery, runtime template macro and native simulator regressions."""
import copy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import threading
import unittest
import urllib.error
import urllib.request
from urllib.parse import quote
from unittest.mock import patch


def load(name):
    path = Path(__file__).resolve().parents[1] / 'mock-vmware' / (name + '.py')
    spec = importlib.util.spec_from_file_location('vmware_folders_' + name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


gateway, demo = load('server'), load('demo')
TEMPLATES = [{'templateid': 'vcenter', 'host': demo.TEMPLATES['VMware']},
             {'templateid': 'guest', 'host': demo.TEMPLATES['VMware Guest']}]


class MigrationAPI:
    def __init__(self):
        self.rule = {'itemid': 'rule', 'key_': 'vmware.vm.discovery[{$VMWARE.URL}]',
                     'preprocessing': [], 'lld_macro_paths': [{'lld_macro': '{#VM.TEAM}', 'path': '$.vm_team'}]}
        self.prototype = {'hostid': 'prototype', 'host': demo.HOST + '-vm-{#VM.UUID}',
                          'tags': [{'tag': 'fixture', 'value': demo.OWNER}, {'tag': 'custom', 'value': 'keep'},
                                   {'tag': 'Team', 'value': '{#VM.TEAM}'}],
                          'groupPrototypes': [{'group_prototypeid': 'custom', 'name': 'Custom/{#VM.FOLDER}'},
                                              {'group_prototypeid': 'old', 'name': '{#VM.GROUP}'}]}
        self.macros = {}
        self.item = {'itemid': 'legacy-item', 'description': 'Team is also mapped to a host tag and group.'}
        self.mutations = []

    def call(self, method, params):
        if method == 'discoveryrule.get':
            assert params['hostids'] == ['vcenter']
            return [copy.deepcopy(self.rule)]
        if method == 'hostprototype.get':
            assert params['discoveryids'] == ['rule']
            return [copy.deepcopy(self.prototype)]
        if method == 'usermacro.get':
            macro = self.macros.get(params['filter']['macro'])
            return [copy.deepcopy(macro)] if macro else []
        if method == 'item.get':
            assert params['hostids'] == ['guest']
            return [self.item] if self.item else []
        self.mutations.append((method, copy.deepcopy(params)))
        if method == 'discoveryrule.update':
            self.rule.update(params)
        elif method == 'hostprototype.update':
            self.prototype.update(params)
            for index, group in enumerate(self.prototype['groupPrototypes']):
                group.setdefault('group_prototypeid', 'new-' + str(index))
        elif method == 'usermacro.create':
            self.macros[params['macro']] = params
        elif method == 'item.delete':
            assert params == ['legacy-item']
            self.item = None
        else:
            raise AssertionError(method)
        return {}


def preprocess(payload, macro_value, default_group=demo.DEFAULT_UNMAPPED_GROUP):
    script = demo.folder_preprocessing().replace(demo.FOLDER_MAP_REFERENCE, quote(macro_value, safe=''))
    script = script.replace(demo.DEFAULT_GROUP_REFERENCE, quote(default_group, safe=''))
    program = "const p=JSON.parse(require('fs').readFileSync(0,'utf8')); console.log(new Function('value',p.script)(JSON.stringify(p.rows)));"
    return subprocess.run([os.environ.get('NODE_BINARY', 'node'), '-e', program],
        input=json.dumps({'script': script, 'rows': payload}), text=True, encoding='utf-8', capture_output=True)


class DiscoveryTests(unittest.TestCase):
    def test_migration_removes_legacy_team_features_and_preserves_customizations(self):
        api = MigrationAPI()
        custom = {'type': '21', 'params': 'return value;', 'error_handler': '0', 'error_handler_params': ''}
        api.rule['preprocessing'] = [custom, {'params': '// vmware-module-demo: vCenter Team tag\nreturn value;'}]
        for _ in range(2):
            demo.configure_vm_folders(api, TEMPLATES)
        self.assertEqual(api.rule['preprocessing'][0], custom)
        self.assertEqual(len(api.rule['preprocessing']), 2)
        self.assertIn(demo.FOLDER_MAP_REFERENCE, api.rule['preprocessing'][1]['params'])
        self.assertIn(demo.DEFAULT_GROUP_REFERENCE, api.rule['preprocessing'][1]['params'])
        self.assertEqual(api.rule['lld_macro_paths'],
                         [{'lld_macro': demo.FOLDER_GROUP_MACRO, 'path': '$.vm_folder_group'}])
        self.assertEqual(api.prototype['tags'], [{'tag': 'fixture', 'value': demo.OWNER}, {'tag': 'custom', 'value': 'keep'}])
        self.assertEqual([g['name'] for g in api.prototype['groupPrototypes']],
                         ['Custom/{#VM.FOLDER}', demo.FOLDER_GROUP_MACRO])
        self.assertEqual(json.loads(api.macros[demo.FOLDER_MAP_MACRO]['value']), demo.DEFAULT_FOLDER_GROUPS)
        self.assertEqual(api.macros[demo.DEFAULT_GROUP_MACRO]['value'], demo.DEFAULT_UNMAPPED_GROUP)
        self.assertEqual(sum(m == 'usermacro.create' for m, _ in api.mutations), 2)
        self.assertEqual(sum(m == 'item.delete' for m, _ in api.mutations), 1)

    def test_existing_macro_value_is_never_overwritten_by_setup(self):
        api = MigrationAPI()
        api.macros = {demo.FOLDER_MAP_MACRO: {'value': '{"FB-STP":"Customer group"}'},
                      demo.DEFAULT_GROUP_MACRO: {'value': 'Customer unassigned'}}
        demo.configure_vm_folders(api, TEMPLATES)
        self.assertEqual(api.macros[demo.FOLDER_MAP_MACRO]['value'], '{"FB-STP":"Customer group"}')
        self.assertEqual(api.macros[demo.DEFAULT_GROUP_MACRO]['value'], 'Customer unassigned')
        self.assertNotIn('usermacro.create', [m for m, _ in api.mutations])

    def test_foreign_prototype_is_rejected_before_mutation(self):
        api = MigrationAPI()
        api.prototype['tags'] = []
        with self.assertRaisesRegex(RuntimeError, 'not owned'):
            demo.configure_vm_folders(api, TEMPLATES)
        self.assertEqual(api.mutations, [])

    def test_fresh_clone_contains_template_macro_and_only_folder_grouping(self):
        source = {'zabbix_export': {'templates': [{'template': 'VMware', 'discovery_rules': [
            {'key': 'vmware.vm.discovery[{$VMWARE.URL}]', 'host_prototypes': [{}]},
            {'key': 'vmware.hv.discovery[{$VMWARE.URL}]', 'host_prototypes': [{}]}]}]}}
        original = copy.deepcopy(source)
        template = demo.clone_export(source)['zabbix_export']['templates'][0]
        rule, hypervisors = template['discovery_rules']
        self.assertEqual(source, original)
        self.assertEqual(template['macros'][0]['macro'], demo.FOLDER_MAP_MACRO)
        self.assertEqual(template['macros'][1]['macro'], demo.DEFAULT_GROUP_MACRO)
        self.assertEqual(template['macros'][1]['value'], demo.DEFAULT_UNMAPPED_GROUP)
        self.assertEqual(rule['host_prototypes'][0]['group_prototypes'], [{'name': demo.FOLDER_GROUP_MACRO}])
        self.assertNotIn('Team', [t['tag'] for t in rule['host_prototypes'][0]['tags']])
        self.assertNotIn('group_prototypes', hypervisors['host_prototypes'][0])

    def test_main_folder_controls_group_and_subfolders_are_ignored(self):
        folders = ['FB-STP', 'FB-MSP', 'FB-MSP/05_app', 'FB-OPS/Apps', 'Prod/FB-STP/Apps',
                   'FB-STP/FB-MSP/Apps', 'FB-STP-old', '', 'FB-MSP/05_app/Restricted']
        payload = [{'{#VM.FOLDER}': folder} for folder in folders]
        mapping = dict(demo.DEFAULT_FOLDER_GROUPS, **{'FB-MSP/05_app': 'Must not override main folder'})
        result = preprocess(payload, json.dumps(mapping))
        self.assertEqual(result.returncode, 0, result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual([r['vm_folder_group'] for r in rows],
                         ['STP', 'MSP', 'MSP', 'RUN', demo.DEFAULT_UNMAPPED_GROUP, 'STP',
                          demo.DEFAULT_UNMAPPED_GROUP, demo.DEFAULT_UNMAPPED_GROUP, 'MSP'])
        self.assertTrue(all('vm_team' not in row and 'vm_group' not in row for row in rows))

    def test_vm_root_always_uses_default_even_with_a_vm_mapping(self):
        payload = [{'{#VM.FOLDER}': 'vm'}, {'{#VM.FOLDER}': 'vm/FB-MSP'}, {}, {'{#VM.FOLDER}': ''}]
        mapping = dict(demo.DEFAULT_FOLDER_GROUPS, vm='Must not use this group')
        result = preprocess(payload, json.dumps(mapping), 'Custom fallback')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([r['vm_folder_group'] for r in json.loads(result.stdout)], ['Custom fallback'] * 4)

    def test_macro_values_with_punctuation_unicode_and_quotes_remain_data(self):
        mapping = {'FB.STP;OPS': "Ops, RUN's servers é", '__proto__': 'Special'}
        result = preprocess({'data': [{'{#VM.FOLDER}': folder} for folder in mapping]}, json.dumps(mapping))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([r['vm_folder_group'] for r in json.loads(result.stdout)['data']], list(mapping.values()))

    def test_invalid_macro_values_fail_clearly(self):
        for value in ('invalid', '[]', 'null', '{"FB-STP":42}', '{"FB-STP":""}', '{"FB-STP":"/bad"}'):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    demo.parse_folder_mapping(value)
                result = preprocess([], value)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Invalid folder mapping macro', result.stderr)

    def test_default_macro_changes_unmatched_folders_without_changing_mapped_groups(self):
        default = "Ops's unassigned, \u00e9"
        payload = [{'{#VM.FOLDER}': 'FB-STP'}, {'{#VM.FOLDER}': 'Other'}, {}]
        result = preprocess(payload, json.dumps(demo.DEFAULT_FOLDER_GROUPS), default)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([r['vm_folder_group'] for r in json.loads(result.stdout)], ['STP', default, default])
        self.assertNotIn(demo.DEFAULT_UNMAPPED_GROUP, demo.folder_preprocessing())

    def test_invalid_default_macro_fails_clearly(self):
        for value in ('', '/bad', 'bad/', 'bad//path', ' bad '):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    demo.parse_default_group(value)
                result = preprocess([], '{}', value)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Invalid default folder group macro', result.stderr)


class SimulatorTests(unittest.TestCase):
    def test_folder_seeding_preserves_existing_user_moves(self):
        client = gateway.SimulatorInventory()
        folders = {name: {'name': name, 'parent': 'root'} for name in ('FB-STP', 'FB-MSP', 'FB-OPS', 'Custom')}
        vms = [{'id': 'vm-1', 'parent': 'root'}, {'id': 'vm-2', 'parent': 'Custom'}]
        calls = []
        def soap(method, fill):
            op = gateway.ET.Element(method)
            fill(op)
            calls.append((method, gateway.text(op, '_this'), gateway.text(op, 'list')))
            return gateway.ET.fromstring('<Envelope><returnval>task-1</returnval></Envelope>')
        with patch.object(client, 'soap', side_effect=soap), patch.object(client, 'wait_task') as wait:
            client.seed_vm_folders(vms, folders, {'root'}, 'collector')
        self.assertEqual(calls, [('MoveIntoFolder_Task', 'FB-STP', 'vm-1')])
        self.assertEqual(vms[1]['parent'], 'Custom')
        wait.assert_called_once_with('collector', 'task-1')

    def test_native_tagging_passthrough_preserves_collector_protocol(self):
        calls = []
        class Upstream(BaseHTTPRequestHandler):
            def log_message(self, *_): pass
            def native(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                calls.append((self.command, self.path, dict(self.headers), json.loads(body) if body else None))
                self.send_response(200)
                payload = json.dumps({'value': 'native-session' if self.path.endswith('/session') else []}).encode()
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
            do_POST = native
            do_DELETE = native
        upstream = ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        proxy = ThreadingHTTPServer(('127.0.0.1', 0), gateway.Handler)
        for http in (upstream, proxy):
            threading.Thread(target=http.serve_forever, daemon=True).start()
        try:
            with patch.dict(os.environ, {'VMWARE_SIMULATOR_URL': 'http://127.0.0.1:' + str(upstream.server_port)}):
                url = 'http://127.0.0.1:' + str(proxy.server_port) + gateway.SimulatorInventory.prefix + 'tag-association?~action=list-attached-tags'
                data = {'object_id': {'id': 'vm-actual', 'type': 'VirtualMachine'}}
                request = urllib.request.Request(url, json.dumps(data).encode())
                with self.assertRaises(urllib.error.HTTPError) as error:
                    urllib.request.urlopen(request)
                self.assertEqual(error.exception.code, 401)
                request.add_header('vmware-api-session-id', gateway.SESSION)
                self.assertEqual(json.load(urllib.request.urlopen(request)), {'value': []})
                self.assertEqual(calls[1][2]['Vmware-Api-Session-Id'], 'native-session')
                self.assertEqual(calls[1][3], data)
                self.assertEqual(calls[2][0], 'DELETE')
        finally:
            for http in (proxy, upstream):
                http.shutdown()
                http.server_close()


if __name__ == '__main__':
    unittest.main()
