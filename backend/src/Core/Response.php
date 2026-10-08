<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 统一响应格式： {code:0, msg:'ok', data:...}
 */
final class Response
{
    public static function json(mixed $data, int $httpCode = 200): never
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, string $msg = 'ok'): never
    {
        self::json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 业务失败，HTTP 仍 200 */
    public static function fail(string $msg, int $code = 1): never
    {
        self::json(['code' => $code, 'msg' => $msg, 'data' => null], 200);
    }

    /** 协议层错误 */
    public static function error(string $msg, int $httpCode = 400): never
    {
        self::json(['code' => $httpCode, 'msg' => $msg, 'data' => null], $httpCode);
    }

    public static function notFound(string $msg = '资源不存在'): never
    {
        self::error($msg, 404);
    }
}
