#!/usr/bin/env python3
"""vcsim SOAP gateway plus the appliance health REST API used by the template."""
import base64
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import select
import socket
import threading
from urllib.parse import urlsplit

SCENARIOS = ('healthy', 'degraded', 'unresponsive')
SESSION = 'vmware-module-demo-session'
AUTH = 'Basic ' + base64.b64encode(b'mock:mock').decode()


class State:
    def __init__(self):
        self.scenario = 'healthy'
        self.changed = threading.Condition()

    def set(self, scenario):
        if scenario not in SCENARIOS:
            raise ValueError('Unknown scenario')
        with self.changed:
            self.scenario = scenario
            self.changed.notify_all()


STATE = State()


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
            if path == '/_mock/scenario' and self.command == 'POST':
                try:
                    STATE.set(json.loads(body)['scenario'])
                except (ValueError, KeyError, TypeError):
                    return self.reply(400, {'error': 'Expected scenario: ' + ', '.join(SCENARIOS)})
            elif path != '/_mock/status' or self.command != 'GET':
                return self.reply(404, {'error': 'Unknown control endpoint'})
            return self.reply(200, {'scenario': STATE.scenario, 'scenarios': SCENARIOS})
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
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            payload = response.read()
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
