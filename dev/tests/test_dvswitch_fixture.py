"""Synthetic port telemetry stays tied to real simulator inventory and SDK criteria."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET

spec = importlib.util.spec_from_file_location('dvs_fixture',
    Path(__file__).resolve().parents[1] / 'mock-vmware/server.py')
gateway = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gateway)

NATIVE = b'''<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
<Body><FetchDVPortsResponse xmlns="urn:vim25">
<returnval><key>0</key><dvsUuid>uuid-1</dvsUuid><portgroupKey>pg-uplink</portgroupKey></returnval>
<returnval><key>0</key><dvsUuid>uuid-1</dvsUuid><portgroupKey>pg-guest</portgroupKey></returnval>
</FetchDVPortsResponse></Body></Envelope>'''


def request(**criteria):
    op = ET.fromstring('<FetchDVPorts xmlns="urn:vim25"><_this type="DistributedVirtualSwitch">dvs-1</_this></FetchDVPorts>')
    if criteria:
        node = gateway.xml(op, 'criteria')
        for key, values in criteria.items():
            for value in values if isinstance(values, list) else [values]:
                gateway.xml(node, key, value)
    return op


class FixtureTests(unittest.TestCase):
    def test_error_counters_survive_scenario_recovery(self):
        with patch.object(gateway.time, 'monotonic', return_value=0):
            state = gateway.State()
        with patch.object(gateway.time, 'monotonic', return_value=10):
            state.set('degraded')
        with patch.object(gateway.time, 'monotonic', return_value=20):
            self.assertEqual(state.port_state()[2], 10)
        with patch.object(gateway.time, 'monotonic', return_value=30):
            state.set('healthy')
        with patch.object(gateway.time, 'monotonic', return_value=40):
            self.assertEqual(state.port_state()[2], 20)
        with patch.object(gateway.time, 'monotonic', return_value=50):
            state.set('degraded')
        with patch.object(gateway.time, 'monotonic', return_value=60):
            self.assertEqual(state.port_state()[2], 30)

    def setUp(self):
        self.fixture = gateway.DvswitchFixture()
        self.inventory = {'switches': {'dvs-1': {'uuid': 'uuid-1', 'uplinks': ['pg-uplink']}}, 'nics': [
            dict(vm='vm-1', name='Database', host='host-1', nic='4000', mac='00:11:22:33:44:55',
                 uuid='uuid-1', group='pg-guest', key='', connected=True),
            dict(vm='vm-2', name='Web', host='host-2', nic='4001', mac='00:11:22:33:44:66',
                 uuid='uuid-1', group='pg-guest', key='actual-port-2', connected=True),
            dict(vm='vm-foreign', name='Other switch', host='host-3', nic='4000', mac='',
                 uuid='uuid-other', group='pg-foreign', key='', connected=True)]}

    def ports(self, scenario='healthy', faults=0, **criteria):
        data = self.fixture.enrich(NATIVE, request(**criteria), self.inventory, scenario, 1700000000, faults)
        return ET.fromstring(data).findall('.//{*}returnval')

    def test_full_payload_and_actual_vm_identity(self):
        ports = self.ports()
        self.assertEqual(len(ports), 6)  # 2 VMs, 2 uplinks, 2 spare ports.
        self.assertEqual(len({gateway.text(p, 'key') for p in ports}), 6)
        first = ports[0]
        self.assertEqual(gateway.text(first, 'connectee/connectedEntity'), 'vm-1')
        self.assertEqual(gateway.text(first, 'proxyHost'), 'host-1')
        self.assertEqual(gateway.text(first, 'state/runtimeInfo/macAddress'), '00:11:22:33:44:55')
        self.assertEqual(gateway.text(ports[1], 'key'), 'actual-port-2')
        self.assertTrue(gateway.text(first, 'key').startswith('vm-1-nic-'))
        for port in ports:
            self.assertEqual(gateway.text(port, 'dvsUuid'), 'uuid-1')
            self.assertEqual(len(port.findall('{*}state/{*}stats/*')), 16)
            self.assertIn('Synthetic', gateway.text(port, 'state/runtimeInfo/statusDetail'))
            self.assertEqual(gateway.text(port, 'hostLocalPort'), 'false')
            self.assertTrue(gateway.text(port, 'lastStatusChange').endswith('Z'))
        self.assertNotIn(b'vm-foreign', b''.join(ET.tostring(p) for p in ports))
        self.assertEqual(gateway.text(ports[2], 'state/runtimeInfo/vlanIds/end'), '4094')

    def test_degraded_and_recovery(self):
        failed = self.ports('degraded', 7)[0]
        self.assertEqual(gateway.text(failed, 'state/runtimeInfo/linkUp'), 'false')
        self.assertEqual(gateway.text(failed, 'state/runtimeInfo/blocked'), 'true')
        self.assertEqual(gateway.text(failed, 'conflict'), 'true')
        self.assertEqual(gateway.text(failed, 'conflictPortKey'), 'actual-port-2')
        self.assertEqual(gateway.text(failed, 'state/stats/packetsInDropped'), '28')
        recovered = self.ports('healthy', 9)[0]
        self.assertEqual(gateway.text(recovered, 'state/runtimeInfo/linkUp'), 'true')
        self.assertEqual(gateway.text(recovered, 'state/runtimeInfo/blocked'), 'false')
        self.assertEqual(gateway.text(recovered, 'conflict'), 'false')
        self.assertEqual(gateway.text(recovered, 'state/stats/packetsInDropped'), '36')

    def test_cumulative_counters_and_stable_ports(self):
        self.fixture.started = 0
        with patch.object(gateway.time, 'monotonic', return_value=100):
            before = self.ports()
        with patch.object(gateway.time, 'monotonic', return_value=200):
            after = self.ports()
        self.assertEqual([gateway.text(p, 'key') for p in before], [gateway.text(p, 'key') for p in after])
        self.assertGreater(int(gateway.text(after[0], 'state/stats/bytesInUnicast')),
                           int(gateway.text(before[0], 'state/stats/bytesInUnicast')))

    def test_native_selection_criteria(self):
        self.assertEqual(len(self.ports(connected=True, uplinkPort=False)), 2)
        self.assertEqual(len(self.ports(connected=False)), 2)
        self.assertEqual(len(self.ports(uplinkPort=True)), 3)
        self.assertEqual(len(self.ports(nsxPort=True)), 0)
        self.assertEqual(len(self.ports(active=False)), 2)
        self.assertEqual(len(self.ports('degraded', active=False)), 3)
        self.assertEqual(len(self.ports(host='host-1')), 2)
        self.assertEqual(len(self.ports(portKey=['actual-port-2'])), 1)
        self.assertEqual(len(self.ports(inside=True, portgroupKey='pg-guest')), 3)
        self.assertEqual(len(self.ports(inside=False, portgroupKey='pg-guest')), 3)
        self.assertEqual(len(self.ports(inside=False)), 0)
        self.assertEqual(len(self.ports(portgroupKey='nonexistent')), 6, 'Group ignored when inside is unset')

    def test_decode_inventory_and_uplink_fallback(self):
        root = ET.fromstring('''<root xmlns="urn:vim25"><objects><obj type="VirtualMachine">vm-7</obj>
        <propSet><name>name</name><val>Real inventory VM</val></propSet>
        <propSet><name>runtime.host</name><val type="HostSystem">host-8</val></propSet>
        <propSet><name>config.hardware.device</name><val><VirtualDevice><key>4000</key>
        <backing><port><switchUuid>uuid-1</switchUuid><portgroupKey>pg-guest</portgroupKey></port></backing>
        <macAddress>00:11:22:33:44:77</macAddress><connectable><connected>true</connected></connectable>
        </VirtualDevice><VirtualDevice><key>100</key></VirtualDevice></val></propSet></objects>
        <objects><obj type="DistributedVirtualPortgroup">pg-uplink</obj>
        <propSet><name>name</name><val>DVS0-DVUplinks-1</val></propSet>
        <propSet><name>config.distributedVirtualSwitch</name><val>dvs-1</val></propSet></objects>
        <objects><obj type="DistributedVirtualSwitch">dvs-1</obj>
        <propSet><name>uuid</name><val>uuid-1</val></propSet>
        <propSet><name>config.uplinkPortgroup</name><val/></propSet></objects></root>''')
        inventory = self.fixture.decode_inventory(list(root))
        self.assertEqual(inventory['switches']['dvs-1']['uplinks'], ['pg-uplink'])
        self.assertEqual(len(inventory['nics']), 1)
        nic = inventory['nics'][0]
        self.assertEqual((nic['vm'], nic['host'], nic['mac']), ('vm-7', 'host-8', '00:11:22:33:44:77'))

    def test_batch_inventory_cache_and_view_cleanup(self):
        calls = []
        def soap(_upstream, _headers, method, fill):
            operation = ET.Element('request')
            fill(operation)
            calls.append((method, operation))
            if method == 'RetrieveServiceContent':
                return ET.fromstring('<r><returnval><viewManager>viewmgr</viewManager><rootFolder>root</rootFolder><propertyCollector>pc</propertyCollector></returnval></r>')
            if method == 'CreateContainerView':
                return ET.fromstring('<r><returnval>view-1</returnval></r>')
            return ET.fromstring('<r/>')
        with patch.object(self.fixture, 'request', side_effect=soap):
            for _ in range(3):
                self.fixture.inventory(urlsplit('http://simulator:8989'), {'Cookie': 'session-1'})
            self.assertEqual([call[0] for call in calls], ['RetrieveServiceContent', 'CreateContainerView',
                                                         'RetrievePropertiesEx', 'DestroyView'])
            self.fixture.inventory(urlsplit('http://simulator:8989'), {'Cookie': 'session-2'})
            self.assertEqual(len(calls), 8, 'Sessions cannot reuse each other\'s cached inventory')

    def test_faults_are_not_replaced_with_healthy_data(self):
        fault = b'<Envelope><Body><Fault><faultstring>Not authenticated</faultstring></Fault></Body></Envelope>'
        self.assertEqual(self.fixture.enrich(fault, request(), {}, 'healthy', 0, 0), fault)


if __name__ == '__main__':
    unittest.main()
