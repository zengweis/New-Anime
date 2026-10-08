<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 请求封装：方法、路径、参数、输入、IP、Bearer token。
 */
final class Request
{
    private static ?array $body = null;

    public static function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // 支持 _method 覆盖（表单场景）
        if ($m === 'POST' && isset($_POST['_method'])) {
            $m = strtoupper((string) $_POST['_method']);
        }
        return $m;
    }

    public static function path(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }

    /** JSON body（缓存） */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input') ?: '';
        $ct  = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if ($raw !== '' && str_contains($ct, 'json')) {
            $d = json_decode($raw, true);
            self::$body = is_array($d) ? $d : [];
        } else {
            self::$body = $_POST ?: [];
            if ($raw !== '' && !self::$body) {
                parse_str($raw, $parsed);
                self::$body = is_array($parsed) ? $parsed : [];
            }
        }
        return self::$body;
    }

    /** 取输入：body 优先，其次 query */
    public static function input(string $key, mixed $default = null): mixed
    {
        $b = self::body();
        if (array_key_exists($key, $b)) {
            return $b[$key];
        }
        return $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::input($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    public static function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? $default);
    }

    /** 取 token：Authorization: Bearer / X-Token / ?token= */
    public static function token(): string
    {
        $auth = self::header('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        $t = self::header('X-Token');
        if ($t !== '') {
            return $t;
        }
        return (string) ($_GET['token'] ?? '');
    }

    public static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', (string) $_SERVER[$k])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    public static function ua(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
