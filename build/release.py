#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
发布器：构建制品并生成「一键部署」发布清单。

  1. 调用 build/package.py 构建 3 个 ZIP + 单文件脚本
  2. 生成 dist/install.sh（把制品根地址注入引导安装器，唯一需要改的 URL）
  3. 生成 dist/latest.json（版本、各包 URL、SHA256、大小）
  4. 生成 dist/SHA256SUMS 与 dist/RELEASE.md（含上传示例）

用法:
  python3 build/release.py --base-url https://cdn.example.com/mailu
  MAILU_BASE_URL=https://oss.example.com/mailu python3 build/release.py
  python3 build/release.py --base-url ... --version 1.0.1 --notes "修复同步超时"
"""

import hashlib
import importlib.util
import json
import os
import sys
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, 'dist')
DEFAULT_BASE_URL = os.environ.get('MAILU_BASE_URL', 'https://cdn.example.com/mailu')
BOOTSTRAP = os.path.join(ROOT, 'deploy', 'auto', 'bootstrap.sh')
PLACEHOLDER = '__MAILU_BASE_URL__'


def load_packager():
    spec = importlib.util.spec_from_file_location('mailu_packager', os.path.join(ROOT, 'build', 'package.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()


def build(version):
    packager = load_packager()
    argv_backup = sys.argv[:]
    sys.argv = ['package.py', '--version', version]
    try:
        packager.main()
    finally:
        sys.argv = argv_backup


def write(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(content)


def main():
    args = sys.argv[1:]
    base_url = DEFAULT_BASE_URL
    version = '1.0.0'
    notes = ''
    skip_build = False
    i = 0
    while i < len(args):
        arg = args[i]
        if arg == '--base-url' and i + 1 < len(args):
            base_url = args[i + 1]; i += 2; continue
        if arg.startswith('--base-url='):
            base_url = arg.split('=', 1)[1]; i += 1; continue
        if arg == '--version' and i + 1 < len(args):
            version = args[i + 1]; i += 2; continue
        if arg.startswith('--version='):
            version = arg.split('=', 1)[1]; i += 1; continue
        if arg == '--notes' and i + 1 < len(args):
            notes = args[i + 1]; i += 2; continue
        if arg.startswith('--notes='):
            notes = arg.split('=', 1)[1]; i += 1; continue
        if arg == '--notes-file' and i + 1 < len(args):
            with open(args[i + 1], 'r', encoding='utf-8') as fh:
                notes = fh.read().strip(); i += 2; continue
        if arg.startswith('--notes-file='):
            with open(arg.split('=', 1)[1], 'r', encoding='utf-8') as fh:
                notes = fh.read().strip(); i += 1; continue
        if arg == '--skip-build':
            skip_build = True; i += 1; continue
        if arg in ('-h', '--help'):
            print(__doc__)
            return 0
        i += 1

    base_url = base_url.rstrip('/')
    if not skip_build:
        build(version)

    # 扁平版（子目录/虚拟主机上传即用）
    try:
        import importlib.util as _ilu
        _spec = _ilu.spec_from_file_location('webvariant', os.path.join(ROOT, 'build', 'webvariant.py'))
        _mod = _ilu.module_from_spec(_spec)
        _spec.loader.exec_module(_mod)
        _mod.main()
    except SystemExit:
        pass
    except Exception as _e:
        print('[warn] 扁平版构建失败: %s' % _e)

    platform = os.path.join(DIST, 'mailu-platform-%s.zip' % version)
    console = os.path.join(DIST, 'mailu-console-%s.zip' % version)
    deploy = os.path.join(DIST, 'mailu-deploy-%s.zip' % version)
    bundle = os.path.join(DIST, 'mailu-deploy.bundle.sh')
    for path in (platform, console, deploy, bundle):
        if not os.path.isfile(path):
            sys.exit('缺少制品: %s（先运行 build/package.py）' % path)

    # 1) 注入唯一 URL 的引导脚本
    with open(BOOTSTRAP, 'r', encoding='utf-8') as fh:
        bootstrap = fh.read()
    if PLACEHOLDER not in bootstrap:
        sys.exit('bootstrap.sh 缺少 %s 占位符' % PLACEHOLDER)
    install_sh = bootstrap.replace(PLACEHOLDER, base_url)
    occurrences = install_sh.count(base_url)
    if occurrences != 1:
        sys.exit(
            '注入异常：base_url 在 install.sh 中出现 %d 次（应仅为 1 次，即默认值赋值处）。\n'
            '请检查 deploy/auto/bootstrap.sh 是否在守卫/提示文本里直接引用了占位符。' % occurrences
        )
    install_path = os.path.join(DIST, 'install.sh')
    write(install_path, install_sh)
    os.chmod(install_path, 0o755)
    write(install_path + '.sha256', '%s  install.sh\n' % sha256_file(install_path))

    # 2) 发布清单
    def entry(path, name):
        return {
            'url': '%s/%s' % (base_url, os.path.basename(path)),
            'sha256': sha256_file(path),
            'size': os.path.getsize(path),
            'filename': os.path.basename(path),
        }

    manifest = {
        'name': 'mailu-platform',
        'version': version,
        'released_at': datetime.now().strftime('%Y-%m-%dT%H:%M:%S%z'),
        'base_url': base_url,
        'notes': notes or 'Mailu 邮件中台 + Linux 自动部署脚本',
        'min_bootstrap': '1.0.0',
        'files': {
            'platform': entry(platform, 'platform'),
            'console': entry(console, 'console'),
            'deploy': entry(deploy, 'deploy'),
            'bundle': entry(bundle, 'bundle'),
            'console_web': entry(os.path.join(DIST, 'mailu-console-web-%s.zip' % version), 'console_web')
            if os.path.isfile(os.path.join(DIST, 'mailu-console-web-%s.zip' % version)) else {},
            'bootstrap': {
                'url': '%s/install.sh' % base_url,
                'sha256': sha256_file(install_path),
                'size': os.path.getsize(install_path),
                'filename': 'install.sh',
            },
        },
    }
    write(os.path.join(DIST, 'latest.json'), json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')

    # 2.1) 每个制品旁挂 .sha256（引导脚本在 --version 模式下会读取）
    for path in (platform, console, deploy, bundle):
        write(path + '.sha256', '%s  %s\n' % (sha256_file(path), os.path.basename(path)))

    # 3) 校验和
    lines = []
    for path in (install_path, platform, console, deploy, bundle):
        lines.append('%s  %s' % (sha256_file(path), os.path.basename(path)))
    write(os.path.join(DIST, 'SHA256SUMS'), '\n'.join(lines) + '\n')

    # 4) 发布说明 / 上传示例
    release_md = """# 发布清单 {version}

制品根地址（唯一需要改的 URL）：`{base}`
发布时间：{stamp}

## 需要上传的文件

| 文件 | 用途 |
|---|---|
| `install.sh` | 一键引导脚本（`curl -fsSL {base}/install.sh | bash`） |
| `latest.json` | 版本清单，引导脚本据此解析下载地址与校验和 |
| `mailu-platform-{version}.zip` | 完整包（中台 + 部署脚本 + 文档） |
| `mailu-console-{version}.zip` | 仅中台 |
| `mailu-deploy-{version}.zip` | 仅部署脚本包 |
| `mailu-deploy.bundle.sh` | 单文件部署脚本 |
| `SHA256SUMS` | 校验和 |

## 上传示例

```bash
# 阿里云 OSS
ossutil cp -r dist/ oss://your-bucket/mailu/ --include "*"

# AWS S3
aws s3 sync dist/ s3://your-bucket/mailu/ --acl public-read

# 任意对象存储 / CDN（rclone）
rclone copy dist/ remote:your-bucket/mailu/ --progress

# GitHub Releases
gh release create v{version} dist/mailu-*.zip dist/mailu-deploy.bundle.sh --notes "{notes}"
```

## 上线命令

```bash
# 单机
curl -fsSL {base}/install.sh | bash -s -- --domain example.com --hostname mail.example.com

# 指定版本（跳过 latest.json）
bash <(curl -fsSL {base}/install.sh) --version {version} \\
     --domain example.com --hostname mail.example.com --yes

# 批量（本机执行，需免密 SSH）
bash deploy/auto/fleet-deploy.sh --nodes fleet.nodes --parallel 4 --yes
```

## 校验

```bash
curl -fsSL {base}/latest.json | head -20
sha256sum -c SHA256SUMS
```
""".format(version=version, base=base_url, stamp=datetime.now().strftime('%Y-%m-%d %H:%M:%S'), notes=notes or '-')
    write(os.path.join(DIST, 'RELEASE.md'), release_md)

    print('')
    print('发布准备完成，制品根地址: %s' % base_url)
    print('  dist/install.sh        (一键引导脚本, sha256=%s)' % manifest['files']['bootstrap']['sha256'][:16])
    print('  dist/latest.json       (版本 %s)' % version)
    print('  dist/SHA256SUMS / dist/RELEASE.md')
    print('')
    print('上传后即可使用:')
    print('  curl -fsSL %s/install.sh | bash -s -- --domain example.com --hostname mail.example.com' % base_url)
    return 0


if __name__ == '__main__':
    sys.exit(main())