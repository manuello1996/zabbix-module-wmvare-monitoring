"""Exercise the simulator SOAP type adapter through real local HTTP requests."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import os
from pathlib import Path
import threading
import unittest
import urllib.request
from unittest.mock import Mock, patch
import xml.etree.ElementTree as ET

spec = importlib.util.spec_from_file_location('vmware_gateway',
    Path(__file__).resolve().parents[1] / 'mock-vmware/server.py')
gateway = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gateway)


class GatewayTests(unittest.TestCase):
    def exchange(self, body, payload, inventory):
        requests = []
        class Upstream(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass
            def do_POST(self):
                requests.append(self.rfile.read(int(self.headers['Content-Length'])))
                self.send_response(200)
                self.send_header('Content-Type', 'text/xml')
                self.send_header('Set-Cookie', 'vmware_soap_session=fixture')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
        upstream = ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        proxy = ThreadingHTTPServer(('127.0.0.1', 0), gateway.Handler)
        fixture = gateway.DvswitchFixture()
        fixture.inventory = inventory
        for server in [upstream, proxy]:
            threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            with patch.dict(os.environ, {'VMWARE_SIMULATOR_URL': 'http://127.0.0.1:' + str(upstream.server_port)}), \
                    patch.object(gateway, 'DVS', fixture):
                request = urllib.request.Request('http://127.0.0.1:' + str(proxy.server_port) + '/sdk', body,
                    {'Content-Type': 'text/xml', 'Cookie': 'vmware_soap_session=fixture'})
                with urllib.request.urlopen(request, timeout=3) as response:
                    return response.read(), response.headers, requests, fixture.error
        finally:
            for server in [proxy, upstream]:
                server.shutdown()
                server.server_close()

    def test_fetch_enrichment_and_criteria_over_http(self):
        request = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPorts><_this type="VmwareDistributedVirtualSwitch">dvs-1</_this><criteria><connected>true</connected></criteria></FetchDVPorts></Body></Envelope>'
        native = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPortsResponse><returnval><key>0</key><portgroupKey>pg-1</portgroupKey></returnval></FetchDVPortsResponse></Body></Envelope>'
        inventory = Mock(return_value={'switches': {'dvs-1': {'uuid': 'uuid', 'uplinks': []}}, 'nics': [
            dict(vm='vm-1', name='Actual VM', host='host-1', nic='4000', mac='00:11:22:33:44:55',
                 uuid='uuid', group='pg-1', key='', connected=True)]})
        payload, headers, requests, error = self.exchange(request, native, inventory)
        ports = ET.fromstring(payload).findall('.//{*}returnval')
        self.assertEqual(len(ports), 1, 'Connected criterion excludes the modeled unused port')
        self.assertEqual(gateway.text(ports[0], 'connectee/connectedEntity'), 'vm-1')
        self.assertNotIn(b'criteria', requests[0], 'Native filtering happens after modeling')
        self.assertEqual(headers['Set-Cookie'], 'vmware_soap_session=fixture')
        self.assertIsNone(error)
        self.assertEqual(inventory.call_count, 1)

    def test_enrichment_failure_restores_original_filter(self):
        request = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPorts><_this>dvs-1</_this><criteria><connected>true</connected></criteria></FetchDVPorts></Body></Envelope>'
        native = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPortsResponse/></Body></Envelope>'
        payload, _headers, requests, error = self.exchange(request, native,
            Mock(side_effect=ValueError('Inventory failed')))
        self.assertEqual(payload, native)
        self.assertEqual(len(requests), 2)
        self.assertNotIn(b'criteria', requests[0])
        self.assertIn(b'criteria', requests[1])
        self.assertIn('unavailable', error)

    def test_soap_fault_preserves_authentication_failure(self):
        request = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPorts><_this>dvs-1</_this></FetchDVPorts></Body></Envelope>'
        fault = b'<Envelope><Body><Fault><faultstring>Not authenticated</faultstring></Fault></Body></Envelope>'
        inventory = Mock()
        payload, _headers, _requests, _error = self.exchange(request, fault, inventory)
        self.assertEqual(payload, fault)
        inventory.assert_not_called()

    def test_native_mode_does_not_enrich(self):
        request = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPorts><_this>dvs-1</_this></FetchDVPorts></Body></Envelope>'
        native = b'<Envelope xmlns="urn:vim25"><Body><FetchDVPortsResponse/></Body></Envelope>'
        inventory = Mock()
        with patch.dict(os.environ, {'VMWARE_MOCK_DVS_DETAILS': 'false'}):
            payload, _headers, requests, _error = self.exchange(request, native, inventory)
        self.assertEqual(payload, native)
        self.assertEqual(len(requests), 1)
        inventory.assert_not_called()

    def test_dvs_types_and_ports_survive_forwarding(self):
        requests = []

        class Upstream(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers['Content-Length']))
                requests.append(body)
                self.send_response(200)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        upstream = ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        proxy = ThreadingHTTPServer(('127.0.0.1', 0), gateway.Handler)
        for server in [upstream, proxy]:
            threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            with patch.dict(os.environ, {'VMWARE_SIMULATOR_URL': 'http://127.0.0.1:' + str(upstream.server_port)}):
                body = b'<obj type="VmwareDistributedVirtualSwitch">dvs-8</obj><key>42</key><uuid>original-uuid</uuid>'
                request = urllib.request.Request('http://127.0.0.1:' + str(proxy.server_port) + '/sdk', body)
                with urllib.request.urlopen(request, timeout=3) as response:
                    self.assertEqual(response.read(), body)
                self.assertEqual(requests[0], body.replace(b'VmwareDistributedVirtualSwitch', b'DistributedVirtualSwitch'))
        finally:
            for server in [proxy, upstream]:
                server.shutdown()
                server.server_close()


if __name__ == '__main__':
    unittest.main()
