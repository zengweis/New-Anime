<?php
/**
 * 配置模板 —— 复制为 config.php 后填写真实值。
 * config.php 不应提交到版本库。
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'anime_new',
        'user'    => 'anime',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // 令牌签名密钥：64 位随机 hex。改动会让所有已登录用户掉线。
    'secret' => 'CHANGE_ME_64_HEX',

    // 生产环境置 false，出错时不再回显堆栈
    'debug' => false,

    'timezone' => 'Asia/Shanghai',

    // 允许跨域的前端源；同域部署留空数组即可
    'cors' => [],
];
