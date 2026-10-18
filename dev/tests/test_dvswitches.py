"""Run: python -m unittest discover -s dev/tests -p 'test_dvswitches.py'.

Requires PyYAML and Node.js. Executes the JavaScript shipped inside the template.
"""
import json
import os
from pathlib import Path
import subprocess
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = yaml.safe_load((ROOT / 'templates/vmware-dvswitches.yaml').read_text())['zabbix_export']['templates'][0]
SCRIPT = TEMPLATE['discovery_rules'][0]['item_prototypes'][1]['preprocessing'][0]['parameters'][0]


def summarize(payload, script=SCRIPT):
    runner = "const x = JSON.parse(require('fs').readFileSync(0, 'utf8')); console.log(new Function('value', x.script)(JSON.stringify(x.payload)));"
    result = subprocess.run([os.environ.get('NODE_BINARY', 'node'), '-e', runner], input=json.dumps({'script': script, 'payload': payload}),
                            text=True, capture_output=True)
    if result.returncode:
        raise ValueError(result.stderr)
    return json.loads(result.stdout)


class PortSummaryTests(unittest.TestCase):
    def test_hypervisor_inventory_names(self):
        item = next(item for item in TEMPLATE['items'] if 'hv.discovery' in item['key'])
        self.assertEqual(item['key'], 'vmware.hv.discovery["{$VMWARE.URL}"]')
        script = item['preprocessing'][0]['parameters'][0]
        self.assertEqual(summarize([{'{#HV.ID}': 'host-34', '{#HV.NAME}': 'esxi-02'}], script),
                         [{'id': 'host-34', 'name': 'esxi-02'}])
        with self.assertRaises(ValueError):
            summarize([{'{#HV.UUID}': 'uuid'}], script)

    def test_vm_inventory_names(self):
        item = TEMPLATE['items'][0]
        self.assertEqual(item['key'], 'vmware.vm.discovery["{$VMWARE.URL}"]')
        script = item['preprocessing'][0]['parameters'][0]
        result = summarize([{'{#VM.ID}': 'vm-1', '{#VM.NAME}': 'Database', '{#VM.UUID}': 'uuid'},
                            {'{#VM.ID}': 'vm-2', '{#VM.NAME}': 'Web'}], script)
        self.assertEqual(result, [{'id': 'vm-1', 'name': 'Database'}, {'id': 'vm-2', 'name': 'Web'}])
        self.assertEqual(summarize([], script), [])
        with self.assertRaises(ValueError):
            summarize([{'{#VM.UUID}': 'uuid'}], script)

    def test_discovery_macro_compatibility(self):
        script = TEMPLATE['discovery_rules'][0]['preprocessing'][0]['parameters'][0]
        for prefix in ['DVS', 'DVSWITCH']:
            result = summarize([{'{#' + prefix + '.NAME}': 'switch', '{#' + prefix + '.UUID}': 'uuid'}], script)
            self.assertEqual(result[0]['{#DVS.NAME}'], 'switch')
            self.assertEqual(result[0]['{#DVS.UUID}'], 'uuid')
        with self.assertRaises(ValueError):
            summarize([{}], script)

    def test_mixed_states(self):
        connected = {'connectedEntity': {'@type': 'VirtualMachine', '#text': 'vm-1'}}
        ports = [
            {'key': '1', 'connectee': connected, 'state': {'runtimeInfo': {'linkUp': 'true', 'blocked': 'false'}}},
            {'key': '2', 'connectee': connected, 'conflict': 'true',
             'state': {'runtimeInfo': {'linkUp': 'false', 'blocked': 'true'}}},
            {'key': '3', 'state': {'runtimeInfo': {'linkUp': False}}},
            {'key': '4', 'connectee': connected},  # Missing state is not a failed link.
        ]
        self.assertEqual(summarize({'FetchDVPortsResponse': {'returnval': ports}}),
                         dict(ports=4, connected=3, link_up=1, connected_down=1, blocked=1, conflicts=1))

    def test_singleton_and_boolean_flags(self):
        result = summarize({'FetchDVPortsResponse': {'returnval':
            {'key': '0', 'conflict': True, 'state': {'runtimeInfo': {'linkUp': True, 'blocked': True}}}}})
        self.assertEqual((result['ports'], result['conflicts'], result['link_up'], result['blocked']), (1, 1, 1, 1))

    def test_empty_response(self):
        for response in [None, {}, {'returnval': None}, {'returnval': []}]:
            self.assertEqual(summarize({'FetchDVPortsResponse': response})['ports'], 0)

    def test_invalid_payload_is_not_healthy_zero(self):
        for payload in [{}, {'Fault': {}}, {'FetchDVPortsResponse': {'returnval': 'invalid'}}]:
            with self.assertRaises(ValueError):
                summarize(payload)

    def test_only_native_checks_use_credentials(self):
        rule = TEMPLATE['discovery_rules'][0]
        self.assertEqual(rule['key'], 'vmware.dvswitch.discovery[{$VMWARE.URL}]')
        self.assertEqual(len(rule['item_prototypes']), 8)
        self.assertNotIn('templates', TEMPLATE)
        self.assertFalse(any(m['macro'] in ('{$VMWARE.URL}', '{$VMWARE.USERNAME}', '{$VMWARE.PASSWORD}')
                             for m in TEMPLATE['macros']))
        keys = {i['key'] for i in rule['item_prototypes']}
        for item in rule['item_prototypes']:
            if item['type'] == 'DEPENDENT':
                self.assertIn(item['master_item']['key'], keys)


if __name__ == '__main__':
    unittest.main()
