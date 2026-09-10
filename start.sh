#!/usr/bin/env bash
# 本地开发唯一启动入口，不操作其他项目，不执行迁移或重置 APP_KEY。
set -Eeuo pipefail
cd "$(dirname "$0")"
[[ -f .env ]] || { echo 'Copy .env.example to .env and configure it first.'; exit 65; }
if ! grep -Eq "^[[:space:]]*APP_ENV[[:space:]]*=[[:space:]]*['\"]?local['\"]?[[:space:]]*(#.*)?$" .env; then
    echo 'This command is for APP_ENV=local only. Use the release workflow for production.'
    exit 65
fi
docker compose -f docker-compose.yml up -d --build
docker compose -f docker-compose.yml ps
