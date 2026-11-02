#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Mock Mailu REST API (v2 style) used for console smoke tests."""
import json
import re
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlparse, parse_qs

TOKEN = 'testtoken123'
DOMAINS = {}
USERS = {}
ALIASES = {}


def seed():
    DOMAINS['example.com'] = {
        'name': 'example.com', 'comment': 'seed domain', 'max_users': -1, 'max_aliases': -1,
        'max_quota_bytes': 0, 'signup_enabled': False, 'alternatives': [],
        'dns_mx': 'mail.example.com', 'dns_spf': 'v=spf1 mx ~all',
        'dns_dkim': 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkEXAMPLEKEY',
        'dns_dmarc': 'v=DMARC1; p=quarantine; rua=mailto:postmaster@example.com',
    }
    DOMAINS['example.net'] = {
        'name': 'example.net', 'comment': '', 'max_users': 10, 'max_aliases': 20,
        'max_quota_bytes': 0, 'signup_enabled': True, 'alternatives': ['alt.example.net'],
        'dns_mx': 'mail.example.com', 'dns_spf': 'v=spf1 mx ~all', 'dns_dkim': '', 'dns_dmarc': '',
    }
    USERS['admin@example.com'] = {
        'email': 'admin@example.com', 'comment': '管理员', 'quota_bytes': 0, 'quota_bytes_used': 1024,
        'global_admin': True, 'enabled': True, 'enable_imap': True, 'enable_pop': True,
        'forward_enabled': False, 'forward_destination': [], 'displayed_name': 'Admin',
        'spam_enabled': True, 'spam_threshold': 80,
    }
    USERS['zhangsan@example.com'] = {
        'email': 'zhangsan@example.com', 'comment': '销售部', 'quota_bytes': 2147483648,
        'quota_bytes_used': 1073741824, 'global_admin': False, 'enabled': True, 'enable_imap': True,
        'enable_pop': True, 'forward_enabled': False, 'forward_destination': [],
        'displayed_name': '张三', 'spam_enabled': True, 'spam_threshold': 80,
    }
    ALIASES['info@example.com'] = {
        'email': 'info@example.com', 'destination': ['zhangsan@example.com'],
        'comment': '客服邮箱',
    }


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, fmt, *args):
        sys.stderr.write('[mock] ' + (fmt % args) + '\n')

    def _send(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _auth(self):
        header = self.headers.get('Authorization', '')
        if header != 'Bearer ' + TOKEN:
            self._send(401, {'code': 401, 'message': 'Invalid authorization header'})
            return False
        return True

    def _body(self):
        length = int(self.headers.get('Content-Length') or 0)
        if not length:
            return {}
        raw = self.rfile.read(length).decode('utf-8')
        try:
            return json.loads(raw)
        except Exception:
            return {}

    def _path(self):
        parsed = urlparse(self.path)
        return unquote(parsed.path), parse_qs(parsed.query)

    def route(self, method):
        path, query = self._path()
        if path == '/__reset':
            DOMAINS.clear()
            USERS.clear()
            ALIASES.clear()
            seed()
            self._send(200, {'ok': True})
            return
        if not self._auth():
            return
        if not path.startswith('/api/v1'):
            self._send(404, {'code': 404, 'message': 'not found'})
            return
        rest = path[len('/api/v1'):]
        data = self._body() if method in ('POST', 'PATCH', 'PUT') else {}

        if rest in ('/domain', '/domain/'):
            if method == 'GET':
                self._send(200, sorted(DOMAINS.values(), key=lambda d: d['name']))
            elif method == 'POST':
                name = (data.get('name') or '').lower()
                if not name:
                    self._send(400, {'code': 400, 'message': 'Domain name is required'})
                elif name in DOMAINS:
                    self._send(409, {'code': 409, 'message': 'Duplicate domain name %s' % name})
                else:
                    DOMAINS[name] = {
                        'name': name, 'comment': data.get('comment', ''), 'max_users': data.get('max_users', -1),
                        'max_aliases': data.get('max_aliases', -1), 'max_quota_bytes': data.get('max_quota_bytes', 0),
                        'signup_enabled': data.get('signup_enabled', False),
                        'alternatives': data.get('alternatives', []),
                        'dns_mx': 'mail.example.com', 'dns_spf': 'v=spf1 mx ~all', 'dns_dkim': '', 'dns_dmarc': '',
                    }
                    self._send(200, {'code': 200, 'message': 'Domain %s has been created' % name})
            return

        m = re.match(r'^/domain/([^/]+)$', rest)
        if m:
            name = m.group(1).lower()
            if name not in DOMAINS:
                self._send(404, {'code': 404, 'message': 'Domain %s does not exist' % name})
                return
            if method == 'GET':
                self._send(200, DOMAINS[name])
            elif method in ('PATCH', 'PUT'):
                for key in ('comment', 'max_users', 'max_aliases', 'max_quota_bytes', 'signup_enabled', 'alternatives'):
                    if key in data:
                        DOMAINS[name][key] = data[key]
                self._send(200, {'code': 200, 'message': 'Domain %s has been updated' % name})
            elif method == 'DELETE':
                del DOMAINS[name]
                self._send(200, {'code': 200, 'message': 'Domain %s has been deleted' % name})
            return

        m = re.match(r'^/domain/([^/]+)/dkim$', rest)
        if m and method == 'POST':
            name = m.group(1).lower()
            if name in DOMAINS:
                DOMAINS[name]['dns_dkim'] = 'v=DKIM1; k=rsa; p=GENERATEDKEY%s' % name.replace('.', '')
                DOMAINS[name]['dns_dmarc'] = 'v=DMARC1; p=quarantine; rua=mailto:postmaster@%s' % name
                self._send(200, {'code': 200, 'message': 'DKIM/DMARC keys have been generated for domain %s' % name})
            else:
                self._send(404, {'code': 404, 'message': 'Domain %s does not exist' % name})
            return

        m = re.match(r'^/domain/([^/]+)/users$', rest)
        if m and method == 'GET':
            name = m.group(1).lower()
            self._send(200, [u for u in USERS.values() if u['email'].endswith('@' + name)])
            return

        if rest in ('/user', '/user/'):
            if method == 'GET':
                self._send(200, sorted(USERS.values(), key=lambda u: u['email']))
            elif method == 'POST':
                email = (data.get('email') or '').lower()
                if not email or '@' not in email:
                    self._send(400, {'code': 400, 'message': 'Provided email address %s is not a valid email address' % email})
                elif email.split('@')[1] not in DOMAINS:
                    self._send(404, {'code': 404, 'message': 'Domain %s does not exist' % email.split('@')[1]})
                elif email in USERS:
                    self._send(409, {'code': 409, 'message': 'User %s already exists' % email})
                else:
                    USERS[email] = {
                        'email': email, 'comment': data.get('comment', ''),
                        'quota_bytes': int(data.get('quota_bytes', 0)), 'quota_bytes_used': 0,
                        'global_admin': bool(data.get('global_admin', False)),
                        'enabled': bool(data.get('enabled', True)),
                        'enable_imap': bool(data.get('enable_imap', True)),
                        'enable_pop': bool(data.get('enable_pop', True)),
                        'forward_enabled': bool(data.get('forward_enabled', False)),
                        'forward_destination': data.get('forward_destination', []),
                        'displayed_name': data.get('displayed_name', ''),
                        'spam_enabled': bool(data.get('spam_enabled', True)),
                        'spam_threshold': int(data.get('spam_threshold', 80)),
                    }
                    self._send(200, {'code': 200, 'message': 'User %s has been created' % email})
            return

        m = re.match(r'^/user/(.+)$', rest)
        if m:
            email = m.group(1).lower()
            if email not in USERS:
                self._send(404, {'code': 404, 'message': 'User %s cannot be found' % email})
                return
            if method == 'GET':
                self._send(200, USERS[email])
            elif method in ('PATCH', 'PUT'):
                for key, value in data.items():
                    if key in ('raw_password', 'password'):
                        continue
                    USERS[email][key] = value
                self._send(200, {'code': 200, 'message': 'User %s has been updated' % email})
            elif method == 'DELETE':
                del USERS[email]
                self._send(200, {'code': 200, 'message': 'User %s has been deleted' % email})
            return

        if rest in ('/alias', '/alias/'):
            if method == 'GET':
                self._send(200, sorted(ALIASES.values(), key=lambda a: a['email']))
            elif method == 'POST':
                email = (data.get('email') or '').lower()
                if email in ALIASES:
                    self._send(409, {'code': 409, 'message': 'Alias %s already exists' % email})
                else:
                    ALIASES[email] = {'email': email, 'destination': data.get('destination', []), 'comment': data.get('comment', '')}
                    self._send(200, {'code': 200, 'message': 'Alias %s has been created' % email})
            return

        m = re.match(r'^/alias/(.+)$', rest)
        if m:
            email = m.group(1).lower()
            if email not in ALIASES:
                self._send(404, {'code': 404, 'message': 'Alias %s cannot be found' % email})
                return
            if method == 'GET':
                self._send(200, ALIASES[email])
            elif method in ('PATCH', 'PUT'):
                for key in ('destination', 'comment'):
                    if key in data:
                        ALIASES[email][key] = data[key]
                self._send(200, {'code': 200, 'message': 'Alias %s has been updated' % email})
            elif method == 'DELETE':
                del ALIASES[email]
                self._send(200, {'code': 200, 'message': 'Alias %s has been deleted' % email})
            return

        if rest.rstrip('/') == '/swagger.json':
            self._send(200, {'info': {'title': 'Mailu API', 'version': '2.0-mock'}})
            return

        self._send(404, {'code': 404, 'message': 'endpoint not found: %s' % rest})

    def do_GET(self):
        self.route('GET')

    def do_POST(self):
        self.route('POST')

    def do_PATCH(self):
        self.route('PATCH')

    def do_PUT(self):
        self.route('PUT')

    def do_DELETE(self):
        self.route('DELETE')


if __name__ == '__main__':
    seed()
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 9099
    server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    print('mock mailu api on http://127.0.0.1:%d/api/v1 (token %s)' % (port, TOKEN), flush=True)
    server.serve_forever()