<?php

// 原系统静态归属规则作为基线，数据库中人工维护的归属优先。
return json_decode(file_get_contents(resource_path('legacy/influencer-rules.json')), true, 512, JSON_THROW_ON_ERROR);
