# 参与贡献

感谢你愿意改进这个项目。请先读一遍下面几条，能省掉很多来回。

## 开发环境

| 组件 | 要求 |
|---|---|
| PHP | 7.4 – 8.4，扩展：`pdo_sqlite`、`openssl`、`json`、`curl`（或 `allow_open_url`） |
| Shell | bash 4+（脚本里用了数组与 `${var//}`） |
| 可选 | `shellcheck`、`python3`（跑端到端冒烟测试）、Docker（跑真实安装） |

不需要 Composer、不需要 Node.js、不需要数据库服务。

```bash
git clone https://github.com/tinkleviselo/mailu-console.git
cd mailu-console

# 本地起中台
php -S 127.0.0.1:8080 -t mailu-console/public mailu-console/public/router.php
# 浏览器打开 http://127.0.0.1:8080/setup
```

## 运行测试

```bash
make test            # = PHP 语法 + shellcheck + 部署脚本自检 + 端到端冒烟
make lint            # 仅静态检查
make selfcheck       # 部署脚本自检（不需要 Docker / root）
make smoke           # 中台端到端（需要 php + python3）
make build           # 生成 dist/ 制品
```

## 目录约定

| 目录 | 说明 |
|---|---|
| `mailu-console/app` | 核心框架（`Core`）、业务服务（`Service`）、控制器（`Controller`） |
| `mailu-console/views` | 视图，纯 PHP 模板，无模板引擎 |
| `mailu-console/public` | 唯一应该暴露到 Web 的目录 |
| `deploy/lib` | 部署脚本库，按功能拆分；`deploy/install.sh` 是入口 |
| `deploy/tests` | 可独立运行的自检脚本 |
| `build/` | `package.py`（打包）、`release.py`（发布）、`webvariant.py`（扁平版） |
| `docs/` | 部署、中台、API、自动部署、故障排查 |

## 提交规范

采用 [Conventional Commits](https://www.conventionalcommits.org/)：

```
feat(console): 支持按域名批量导出登录信息
fix(deploy): 修正 AUTH_RATELIMIT 单位缺失导致 API 500
docs: 补充 DNSSEC 排查案例
chore(build): 升级 release.py 的制品清单格式
```

## 提交前检查清单

- [ ] `make lint` 通过（PHP `-l` + `shellcheck -S error`）
- [ ] 改动了 `deploy/` → `make selfcheck` 全 PASS
- [ ] 改动了 `mailu-console/` → `make smoke` 全 PASS
- [ ] 新增文件为 UTF-8 **无 BOM**、LF 换行
- [ ] 没有提交 `config/config.php`、`storage/*.sqlite`、`dist/`、任何真实域名/令牌
- [ ] 面向用户的文案保持中文（后台界面与文档）

## 安全相关

请不要在 Issue / PR 里粘贴真实的 API 令牌、密码、域名解析记录或服务器 IP。发现安全问题时按 [SECURITY.md](SECURITY.md) 私下报告。