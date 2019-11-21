<?php
/**
 * 配置项
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/8/17
 * @time 20:13
 */

return [
    'username' => env('USER_NAME'),
    'password' => env('PASSWORD'),
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0)
    ],
];