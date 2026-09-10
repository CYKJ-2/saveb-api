#!/bin/sh
set -eu
# 本地源码挂载保留 dev vendor 和附件属组，不执行迁移、不重新生成密钥。
attachment_gid=$(stat -c %g /data/attachments)
attachment_group=$(getent group "$attachment_gid" | cut -d: -f1 || true)
if [ -z "$attachment_group" ]; then
    attachment_group=business-attachments
    groupadd -g "$attachment_gid" "$attachment_group"
fi
usermod -a -G "$attachment_group" www-data
mkdir -p /data/attachments/api
chown "www-data:$attachment_gid" /data/attachments/api
chmod 2770 /data/attachments/api
exec php-fpm
