#!/bin/bash
# SAVEB ERP - 启动所有服务 (Linux/macOS)

echo "========================================"
echo "  SAVEB ERP - 启动所有服务"
echo "========================================"
echo ""

echo "[1/4] 启动后端服务 (saveb-api)..."
docker compose up -d

echo "[2/4] 配置 Laravel..."
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

echo ""
echo "[3/4] 启动前端服务 (saveb-web)..."
cd ../saveb-web
docker compose up

echo ""
echo "========================================"
echo "  服务状态"
echo "========================================"
echo ""
echo "后端 API:  http://localhost:8080"
echo "前端页面:  http://localhost:5173"
echo "数据库:    localhost:5432"
echo "Redis:     localhost:6379"
echo ""
