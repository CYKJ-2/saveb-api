#!/usr/bin/env bash
# ============================================================
# SAVEB ERP API — Server Pull & Restart (GHCR)
# ============================================================
# 在**生产服务器**上，每次 GitHub Actions 推送新镜像后执行：
#
#   bash ./deploy-pull.sh
#
# 此脚本会：
#   1. 从 GHCR 拉取新镜像（docker compose pull）
#   2. 自动 recreate 容器使用新镜像（不改配置）
#   3. 跑 pending 的数据库迁移
#   4. 清除 Laravel 缓存（让新代码生效）
#
# ⚠️ 前提：
#   - .env 已软链接到 build/env/.prod.env
#   - 服务器已登录 GHCR（首次运行 `docker login ghcr.io`）
#   - docker-compose.yml 等已是符号链接
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

echo "========================================"
echo "  SAVEB API — Pull & Restart"
echo "========================================"
echo

# 读取 .env 中的 IMAGE_TAG
IMAGE_TAG="${IMAGE_TAG:-latest}"
IMAGE_REGISTRY="${IMAGE_REGISTRY:-ghcr.io/YOUR_ORG}"

echo "[1/5] 当前镜像标签..."
echo "  IMAGE_REGISTRY = $IMAGE_REGISTRY"
echo "  IMAGE_TAG      = $IMAGE_TAG"
echo "  完整镜像: $IMAGE_REGISTRY/saveb-api-app:$IMAGE_TAG"
echo

# ── 1. 检查 GHCR 登录状态 ────────────────────────────────────
echo "[2/5] 检查 GHCR 登录状态..."
if docker manifest inspect "$IMAGE_REGISTRY/saveb-api-app:$IMAGE_TAG" > /dev/null 2>&1; then
    echo "  ✅ 可以访问 GHCR 镜像"
else
    echo "  ❌ 无法访问 GHCR 镜像，请先登录："
    echo "     docker login ghcr.io"
    echo "     # 输入 GitHub Personal Access Token（需要 packages:read 权限）"
    exit 1
fi
echo

# ── 2. 拉取最新镜像 ──────────────────────────────────────────
echo "[3/5] 从 GHCR 拉取最新镜像..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
echo

# ── 3. 重启容器（自动 recreate 使用新镜像）─────────────────────
echo "[4/5] 重启服务（使用新镜像）..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
echo

# ── 4. 等待服务就绪 ──────────────────────────────────────────
echo "[5/5] 等待容器就绪..."
sleep 5
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
echo

# ── 5. 迁移 & 缓存 ──────────────────────────────────────────
echo
echo "========================================"
echo "  Deployment Complete"
echo "========================================"
echo
echo "镜像: $IMAGE_REGISTRY/saveb-api-app:$IMAGE_TAG"
echo "容器: $(docker compose -f docker-compose.yml -f docker-compose.prod.yml ps --format json | jq -r 'select(.Service != \"postgres\" and .Service != \"redis\") | .Service + \"=\" + .State' 2>/dev/null || echo '(jq 不可用，跳过详情)')"
echo
echo "如需查看日志："
echo "  docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f"
echo
