#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
构建脚本：
  1. 生成单文件 Linux 部署脚本 dist/mailu-deploy.bundle.sh
  2. 同步到 mailu-console/resources/（供中台"部署中心"生成定制脚本）
  3. 打包 ZIP：中台 / 部署包 / 完整平台包
  4. 生成 MANIFEST.txt 与 SHA256SUMS

用法: python3 build/package.py [--version 1.0.0]
"""

import hashlib
import os
import re
import shutil
import sys
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, 'dist')
CONSOLE = os.path.join(ROOT, 'mailu-console')
DEPLOY = os.path.join(ROOT, 'deploy')
DOCS = os.path.join(ROOT, 'docs')

LIB_ORDER = ['common.sh', 'docker.sh', 'firewall.sh', 'tls.sh', 'mailu.sh', 'report.sh']

BUNDLE_HEADER = """#!/usr/bin/env bash
# ===========================================================================
#  Mailu 一键部署脚本（单文件版，自动生成，请勿手工编辑）
#  生成时间: {stamp}
#  说明    : 该文件由 build/package.py 合并 deploy/lib/*.sh 与 deploy/install.sh 生成。
#            中台的"部署中心"会在此基础上替换下面的参数块，生成带参数的定制脚本。
#  用法    : bash mailu-deploy.bundle.sh --domain example.com --hostname mail.example.com --yes
# ===========================================================================

##MC_PARAMS##
"""


REQUIRED_FUNCTIONS = [
    'mc_log', 'mc_info', 'mc_warn', 'mc_ok', 'mc_err', 'mc_step', 'mc_plain', 'mc_run', 'mc_die', 'mc_hr',
    'mc_confirm', 'mc_cmd_exists', 'mc_random_hex', 'mc_random_password', 'mc_require_root', 'mc_render_template',
    'mc_detect_os', 'mc_pkg_update', 'mc_pkg_install', 'mc_download', 'mc_download_first', 'mc_mirror_urls',
    'mc_ensure_base_tools', 'mc_is_dry_run',
    'mc_docker_installed', 'mc_compose_cmd', 'mc_compose_available', 'mc_docker_running',
    'mc_start_docker_service', 'mc_install_docker', 'mc_compose',
    'mc_firewall_detect', 'mc_firewall_open', 'mc_sysctl_tuning',
    'mc_primary_hostname', 'mc_generate_selfsigned', 'mc_cert_days_left', 'mc_certbot_install', 'mc_certbot_obtain',
    'mc_install_renew_hook', 'mc_tls_prepare', 'mc_ensure_selfsigned_bootstrap',
    'mc_mailu_dirs', 'mc_fetch_package', 'mc_write_compose', 'mc_write_env',
    'mc_compose_pull', 'mc_compose_up', 'mc_compose_ps', 'mc_wait_containers',
    'mc_api_url', 'mc_api_call', 'mc_api_wait', 'mc_json_value', 'mc_bootstrap_admin', 'mc_fetch_dkim_records',
    'mc_status', 'mc_backup', 'mc_restore', 'mc_update', 'mc_render_compose_and_env', 'mc_uninstall',
    'mc_save_config', 'mc_load_saved_config',
    'mc_print_banner', 'mc_print_summary',
    'mc_usage', 'mc_parse_args', 'mc_prompt_missing', 'mc_validate_params', 'mc_install_flow', 'mc_main',
]


def normalize_tree():
    """确保所有文本文件为 UTF-8 无 BOM + LF 换行（Windows 下编辑过也不会带 CRLF）。"""
    extensions = ('.php', '.sh', '.md', '.py', '.yml', '.yaml', '.css', '.js', '.env', '.tpl', '.txt', '.conf')
    names = ('mailu-ctl', 'mailu-backup.sh', 'mailu-update.sh', 'mailu-uninstall.sh', 'fetch-package.sh',
             'Dockerfile', 'install.sh', '.htaccess', '.gitignore', 'router.php')
    changed = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.tools', '.git', '__pycache__', 'dist')]
        for name in files:
            if not (name.endswith(extensions) or name in names):
                continue
            path = os.path.join(base, name)
            try:
                raw = open(path, 'rb').read()
            except OSError:
                continue
            if raw.startswith(b'\xef\xbb\xbf') or b'\r\n' in raw:
                text = raw.decode('utf-8-sig', 'replace').replace('\r\n', '\n').replace('\r', '\n')
                write(path, text)
                changed += 1
    return changed


def scan_encoding():
    """检测 CJK 字符后紧跟 ASCII '?' 这类编码损坏特征（发布前警示，不阻断构建）。"""
    import re as _re
    pattern = _re.compile(r'[\u4e00-\u9fff\uff00-\uffef]\?')
    hits = []
    exts = ('.php', '.sh', '.md', '.py', '.yml', '.yaml', '.css', '.js', '.tpl', '.txt', '.conf', '.example', '.nodes')
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.tools', '.git', '__pycache__', 'dist')]
        for name in files:
            if not name.endswith(exts) and name not in ('mailu-ctl', 'Dockerfile', 'Caddyfile', 'install.sh'):
                continue
            path = os.path.join(base, name)
            try:
                text = open(path, encoding='utf-8').read()
            except (OSError, UnicodeDecodeError):
                continue
            for index, line in enumerate(text.split('\n'), 1):
                if pattern.search(line):
                    hits.append('%s:%d %s' % (os.path.relpath(path, ROOT), index, line.strip()[:80]))
    if hits:
        print('[警告] 检测到疑似编码损坏（CJK 后紧跟 ?）: %d 处' % len(hits))
        for hit in hits[:5]:
            print('   ' + hit)
    return len(hits)


def read(path):
    with open(path, 'r', encoding='utf-8') as fh:
        return fh.read()


def write(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(content)


def strip_shebang(text):
    lines = [line for line in text.split('\n') if not line.startswith('#!')]
    return '\n'.join(lines).strip('\n')


def build_bundle():
    parts = [BUNDLE_HEADER.format(stamp=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))]
    for name in LIB_ORDER:
        path = os.path.join(DEPLOY, 'lib', name)
        if not os.path.isfile(path):
            raise SystemExit('缺少库文件: ' + path)
        parts.append('# ---------------------------------------------------------------------------')
        parts.append('# 合并自 deploy/lib/%s' % name)
        parts.append('# ---------------------------------------------------------------------------')
        parts.append(strip_shebang(read(path)))
    install_path = os.path.join(DEPLOY, 'install.sh')
    parts.append('# ---------------------------------------------------------------------------')
    parts.append('# 合并自 deploy/install.sh（入口，最后执行 main）')
    parts.append('# ---------------------------------------------------------------------------')
    parts.append(strip_shebang(read(install_path)))
    bundle = '\n\n'.join(parts) + '\n'
    # 参数块占位符必须唯一存在
    if bundle.count('##MC_PARAMS##') != 1:
        raise SystemExit('参数占位符异常: %d' % bundle.count('##MC_PARAMS##'))
    missing = [name for name in REQUIRED_FUNCTIONS if ('\n%s()' % name) not in bundle and not bundle.startswith('%s()' % name)]
    if missing:
        raise SystemExit('部署脚本缺少必要函数（合并顺序或库文件缺失）: %s' % ', '.join(missing))
    return bundle


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()


def zip_dir(zip_path, entries, exclude=None, manifest_prefix=''):
    """entries: [(绝对路径 or 目录, zip 内前缀)]"""
    exclude = exclude or []
    files = 0
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for src, prefix in entries:
            if os.path.isfile(src):
                zf.write(src, prefix or os.path.basename(src))
                files += 1
                continue
            for base, dirs, names in os.walk(src):
                dirs[:] = [d for d in dirs if d not in ('.git', '__pycache__', '.tools')]
                for name in names:
                    full = os.path.join(base, name)
                    rel = os.path.relpath(full, src)
                    if rel.replace('\\', '/') in exclude:
                        continue
                    if any(re.match(pattern, rel.replace('\\', '/')) for pattern in exclude):
                        continue
                    if name.endswith(('.sqlite', '.sqlite-wal', '.sqlite-shm', '.pyc')):
                        continue
                    arc = '/'.join([p for p in [prefix, rel.replace('\\', '/')] if p])
                    zf.write(full, arc)
                    files += 1
    return files


def main():
    version = '1.0.0'
    args = sys.argv[1:]
    if '--version' in args:
        version = args[args.index('--version') + 1]

    os.makedirs(DIST, exist_ok=True)
    stamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    broken = scan_encoding()
    normalized = normalize_tree()
    if normalized:
        print('[0/4] 已规范化换行/编码的文件: %d 个' % normalized)

    # 1) 单文件部署脚本
    bundle = build_bundle()
    bundle_path = os.path.join(DIST, 'mailu-deploy.bundle.sh')
    write(bundle_path, bundle)
    resources = os.path.join(CONSOLE, 'resources', 'mailu-deploy.bundle.sh')
    write(resources, bundle)
    print('[1/4] 单文件部署脚本: %s (%.1f KB)' % (bundle_path, len(bundle) / 1024.0))

    # 2) 中台 ZIP
    console_zip = os.path.join(DIST, 'mailu-console-%s.zip' % version)
    console_exclude = ['storage/logs/.*', 'storage/cache/.*', 'storage/deploy/.*', 'storage/schema.lock',
                       'config/config.php', 'storage/mailu.sqlite.*']
    n1 = zip_dir(console_zip, [(CONSOLE, 'mailu-console')], console_exclude)
    print('[2/4] 中台包: %s (%d 个文件)' % (console_zip, n1))

    # 3) 部署脚本包 ZIP
    deploy_zip = os.path.join(DIST, 'mailu-deploy-%s.zip' % version)
    n2 = zip_dir(deploy_zip, [(DEPLOY, 'mailu-deploy'), (bundle_path, 'mailu-deploy/mailu-deploy.bundle.sh')])
    print('[3/4] 部署包: %s (%d 个文件)' % (deploy_zip, n2))

    # 4) 完整平台包
    platform_zip = os.path.join(DIST, 'mailu-platform-%s.zip' % version)
    entries = [
        (CONSOLE, 'mailu-console'),
        (DEPLOY, 'deploy'),
        (DOCS, 'docs'),
        (os.path.join(ROOT, 'README.md'), 'README.md'),
        (os.path.join(ROOT, 'build', 'package.py'), 'build/package.py'),
        (os.path.join(ROOT, 'build', 'release.py'), 'build/release.py'),
    ]
    n3 = zip_dir(platform_zip, entries, console_exclude)
    print('[4/4] 平台包: %s (%d 个文件)' % (platform_zip, n3))

    # 校验和与清单
    artifacts = [bundle_path, console_zip, deploy_zip, platform_zip]
    sums = []
    manifest = ['Mailu 邮件中台 打包清单', '生成时间: %s' % stamp, '版本: %s' % version, '']
    for path in artifacts:
        digest = sha256_file(path)
        size = os.path.getsize(path)
        sums.append('%s  %s' % (digest, os.path.basename(path)))
        manifest.append('%-32s %10.1f KB  sha256=%s' % (os.path.basename(path), size / 1024.0, digest))
        manifest.append('  内容: %s' % path)
    write(os.path.join(DIST, 'SHA256SUMS'), '\n'.join(sums) + '\n')
    write(os.path.join(DIST, 'MANIFEST.txt'), '\n'.join(manifest) + '\n')
    print('\n构建完成，产物位于 %s' % DIST)
    for line in sums:
        print('  ' + line)


if __name__ == '__main__':
    main()