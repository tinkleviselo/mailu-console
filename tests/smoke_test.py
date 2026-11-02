#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
中台端到端冒烟测试（用 tests/mock_mailu.py 模拟 Mailu /api/v1，不依赖真实服务器）。

环境变量：
  CONSOLE_BASE  中台地址，例如 http://127.0.0.1:8099
  MOCK_BASE     模拟 Mailu 地址，例如 http://127.0.0.1:9099
  ADMIN_USER / ADMIN_PASS  安装时创建的管理员（默认 smokeadmin / SmokePass12345）

退出码 0 表示全部通过。
"""
import http.cookiejar
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('CONSOLE_BASE', 'http://127.0.0.1:8099').rstrip('/')
MOCK = os.environ.get('MOCK_BASE', 'http://127.0.0.1:9099').rstrip('/')
USER = os.environ.get('ADMIN_USER', 'smokeadmin')
PASS = os.environ.get('ADMIN_PASS', 'SmokePass12345')

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
ok = 0
bad = 0


def check(name, cond, detail=''):
    global ok, bad
    if cond:
        ok += 1
        print('PASS  %-44s %s' % (name, detail[:80]))
    else:
        bad += 1
        print('FAIL  %-44s %s' % (name, detail[:140]))


def get(path):
    req = urllib.request.Request(BASE + path)
    try:
        with opener.open(req, timeout=60) as r:
            return r.getcode(), r.read().decode('utf-8', 'ignore'), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'ignore'), BASE + path


def post(path, data):
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(BASE + path, data=body,
                                 headers={'Content-Type': 'application/x-www-form-urlencoded'})
    try:
        with opener.open(req, timeout=120) as r:
            return r.getcode(), r.read().decode('utf-8', 'ignore'), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'ignore'), BASE + path


def token(html):
    m = re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ''


def main():
    try:
        urllib.request.urlopen(urllib.request.Request(MOCK + '/__reset', data=b'', method='POST'),
                               timeout=20).read()
    except Exception as exc:
        print('WARN  mock reset: %s' % exc)

    code, html, _ = get('/setup')
    check('GET /setup', code == 200 and '初始化' in html, 'HTTP %s' % code)
    csrf = token(html)

    code, html, url = post('/setup', {
        '_token': csrf, 'app_name': 'Mailu 邮件中台', 'timezone': 'Asia/Shanghai', 'base_path': '',
        'admin_username': USER, 'admin_email': 'admin@example.com',
        'admin_password': PASS, 'admin_password_confirm': PASS,
        'db_driver': 'sqlite', 'db_database': 'smoke.sqlite',
        'mailu_version': '2024.06.61', 'mirror_prefix': '',
    })
    check('POST /setup（安装）', code == 200 and '登录' in html, 'HTTP %s -> %s' % (code, url))

    code, html, _ = get('/login')
    csrf = token(html)
    code, html, _ = post('/login', {'_token': csrf, 'username': USER, 'password': PASS})
    check('POST /login', code == 200 and '总览' in html, 'HTTP %s' % code)

    code, html, _ = get('/servers/create')
    csrf = token(html)
    code, html, _ = post('/servers', {
        '_token': csrf, 'name': 'Mock Mailu', 'base_url': MOCK, 'api_path': '/api/v1',
        'auth_mode': 'bearer', 'api_token': 'testtoken123', 'timeout': '15',
        'verify_tls': '1', 'enabled': '1', 'dkim_selector': 'dkim',
        'imap_host': '127.0.0.1', 'imap_port': '993', 'imap_security': 'ssl',
        'pop3_host': '127.0.0.1', 'pop3_port': '995', 'pop3_security': 'ssl',
        'smtp_host': '127.0.0.1', 'smtp_port': '465', 'smtp_security': 'ssl',
        'sieve_host': '127.0.0.1', 'sieve_port': '4190', 'note': 'smoke',
    })
    check('POST /servers（添加 + 首次同步）', code == 200 and 'Mock Mailu' in html, 'HTTP %s' % code)

    code, html, _ = get('/users')
    check('GET /users（同步出账号）', code == 200 and 'zhangsan@example.com' in html, 'HTTP %s' % code)
    code, html, _ = get('/domains')
    check('GET /domains', code == 200 and 'example.com' in html, 'HTTP %s' % code)

    code, html, _ = get('/users/create')
    csrf = token(html)
    m = re.search(r'name="server_id"[\s\S]{0,300}?value="(\d+)"', html)
    server_id = m.group(1) if m else '1'
    code, html, _ = post('/users', {
        '_token': csrf, 'server_id': server_id, 'email': 'lisi@example.com',
        'password': 'LisiPass12345', 'quota_mb': '1024', 'displayed_name': 'Li Si',
        'enabled': '1', 'enable_imap': '1', 'enable_pop': '1',
        'spam_enabled': '1', 'spam_threshold': '80',
    })
    check('POST /users（创建账号）', code == 200 and 'lisi@example.com' in html, 'HTTP %s' % code)

    code, html, _ = get('/users')
    m = re.search(r'/users/(\d+)">lisi@example\.com', html) or re.search(r'/users/(\d+)', html)
    uid = m.group(1) if m else '0'
    code, html, _ = get('/users/%s/credentials' % uid)
    check('GET /users/{id}/credentials', code == 200 and 'LisiPass12345' in html, 'HTTP %s' % code)

    code, html, _ = get('/deploy')
    check('GET /deploy', code == 200 and '部署中心' in html, 'HTTP %s' % code)
    code, html, _ = get('/healthz.php')
    check('GET /healthz.php', code == 200 and '"ok": true' in html.replace('\n', ' '), 'HTTP %s' % code)
    code, _, _ = get('/api/v1/ping')
    check('GET /api/v1/ping（无令牌应拒绝）', code in (401, 403), 'HTTP %s' % code)

    print()
    print('==== 冒烟测试: PASS=%d FAIL=%d ====' % (ok, bad))
    return 0 if bad == 0 else 1


if __name__ == '__main__':
    sys.exit(main())