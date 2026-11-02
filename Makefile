# Mailu Console - 开发任务
PHP ?= php
PYTHON ?= python3
SHELLCHECK ?= shellcheck

.PHONY: help lint lint-php lint-shell selfcheck smoke test build release clean

help:
	@echo "make lint        PHP 语法 + shellcheck"
	@echo "make selfcheck   部署脚本自检（无需 Docker/root）"
	@echo "make smoke       中台端到端冒烟测试（需要 php + python3）"
	@echo "make test        lint + selfcheck + smoke"
	@echo "make build       生成 dist/ 制品"
	@echo "make release     生成制品 + install.sh + latest.json"
	@echo "make clean       清理构建产物与运行期数据"

lint: lint-php lint-shell

lint-php:
	@echo "== PHP 语法检查 =="
	@find mailu-console -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null
	@echo "OK"

lint-shell:
	@echo "== Shell 语法 + shellcheck =="
	@for f in deploy/install.sh deploy/lib/*.sh deploy/bin/* deploy/auto/*.sh deploy/packages/*.sh deploy/tests/*.sh; do \
		bash -n "$$f" || exit 1; \
	done
	@if command -v $(SHELLCHECK) >/dev/null 2>&1; then \
		$(SHELLCHECK) -S error -s bash deploy/install.sh deploy/lib/*.sh deploy/auto/*.sh deploy/tests/*.sh deploy/bin/mailu-ctl || exit 1; \
	fi
	@echo "OK"

selfcheck:
	@bash deploy/tests/selfcheck.sh

smoke:
	@bash tests/run_smoke.sh

test: lint selfcheck smoke

build:
	@$(PYTHON) build/package.py

release:
	@$(PYTHON) build/release.py --base-url "$${MAILU_BASE_URL:-https://github.com/tinkleviselo/mailu-console/releases/latest/download}"

clean:
	@rm -rf dist tests/.work
	@rm -f mailu-console/config/config.php mailu-console/storage/*.sqlite* mailu-console/storage/schema.lock
	@find mailu-console/storage/logs mailu-console/storage/cache mailu-console/storage/deploy -type f ! -name '.gitignore' -delete 2>/dev/null || true