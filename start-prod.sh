#!/usr/bin/env bash
# ============================================================
# SAVEB ERP API — Production Startup (Linux Server)
# ============================================================
# 在生产服务器上：
#   bash ./start-prod.sh
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

echo "[1/7] 检查 .env 符号链接..."
if [ -L .env ]; then
    TARGET=$(readlink -f .env)
    EXPECTED="$(pwd)/build/env/.prod.env"
    if [ "$TARGET" != "$EXPECTED" ]; then
        echo "  警告：.env 已链接到 $TARGET，应为 $EXPECTED。重新链接..."
        rm -f .env
    fi
fi
if [ ! -L .env ] && [ ! -f .env ]; then
    echo "  创建符号链接 .env -> build/env/.prod.env"
    ln -s build/env/.prod.env .env
fi
echo "  .env -> $(readlink .env || echo '不存在')"
echo

echo "[2/7] 检查 docker-compose 符号链接..."
for f in docker-compose.yml docker-compose.local.yml docker-compose.prod.yml; do
    if [ ! -L "$f" ] || [ ! -f "$f" ]; then
        echo "  警告：$f 不是有效的符号链接"
    else
        echo "  $f -> $(readlink $f)"
    fi
done
echo

echo "[3/7] 重建应用镜像（服务器代码变更通过 rebuild 生效）..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache app
echo

echo "[4/7] 启动所有服务..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
echo

echo "[5/7] 等待 PostgreSQL / Redis 健康..."
sleep 8
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
echo

echo "[6/7] 数据库迁移（首次部署或升级）..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan migrate --force
echo

echo "[7/7] Laravel 生产优化缓存..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan route:cache
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan view:cache
echo

echo "========================================"
echo "  Production Services Started"
echo "========================================"
echo "前端 Nginx/SLB 连接到 saveb-api-nginx:80（容器内部端口）"
echo "queue worker 已自动由 docker compose 拉起"
