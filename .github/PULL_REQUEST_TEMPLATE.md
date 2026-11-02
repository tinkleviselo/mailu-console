## 这个 PR 做了什么

<!-- 一段话说明动机与结果，关联 Issue: fixes #123 -->

## 改动类型

- [ ] 修复 bug（fix）
- [ ] 新功能（feat）
- [ ] 重构 / 性能（refactor / perf）
- [ ] 文档（docs）
- [ ] 构建 / CI（chore）

## 自测

- [ ] `make lint` 通过
- [ ] 改到 `deploy/` → `make selfcheck` 全 PASS
- [ ] 改到 `mailu-console/` → `make smoke` 全 PASS
- [ ] 涉及界面时贴了截图（可选）

## 检查项

- [ ] 没有提交 `config/config.php`、`storage/*.sqlite`、`dist/`
- [ ] 没有真实域名 / 令牌 / 密码 / 服务器 IP
- [ ] 新文件 UTF-8 无 BOM、LF 换行
- [ ] 行为变化已更新 `docs/` 或 `CHANGELOG.md`