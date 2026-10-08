<?php
declare(strict_types=1);

/**
 * 极简自动加载 + 配置装载。所有入口文件先 require 这个。
 */
define('APP_ROOT', dirname(__DIR__));

// ---- autoload: App\Foo\Bar -> src/Foo/Bar.php ----
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- 配置 ----
$__cfgFile = APP_ROOT . '/config/config.php';
if (!is_file($__cfgFile)) {
    http_response_code(500);
    exit('缺少 config/config.php，请先复制 config.example.php 并填写。');
}
$GLOBALS['CFG'] = require $__cfgFile;

if (!function_exists('cfg')) {
    function cfg(string $key, $default = null) {
        return $GLOBALS['CFG'][$key] ?? $default;
    }
}
