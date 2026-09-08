<?php

namespace App\Common;

class RedisKeyDef
{
    // 过期时间设定
    public const int TTL_MINUTE = 60;

    public const int TTL_HOUR = 3600;

    public const int TTL_DAY = 86400;

    public const int TTL_THREE_DAY = 259200;

    public const int TTL_WEEK = 604800;

    //接口请求锁
    public const string API_LOCK_PREFIX = 'API_LOCK_%s';

    // 获取接口请求锁
    public static function getApiLockKey($route, $params = []): string
    {
        $key = $route . http_build_query($params);

        return sprintf(self::API_LOCK_PREFIX, md5($key));
    }
}
