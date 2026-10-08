<?php
declare(strict_types=1);

namespace App\Core;

final class Log
{
    public static function write(string $channel, string $msg, array $ctx = []): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents(
            $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $channel) . '-' . date('Y-m-d') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function tail(string $channel, int $lines = 200): array
    {
        $f = APP_ROOT . '/storage/logs/' . $channel . '-' . date('Y-m-d') . '.log';
        if (!is_file($f)) {
            return [];
        }
        $all = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($all, -$lines);
    }
}
