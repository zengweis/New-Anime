<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 配置读写（setting 表），带进程内缓存。
 */
final class Setting
{
    private static ?array $cache = null;

    public static function all(bool $fresh = false): array
    {
        if (self::$cache === null || $fresh) {
            $rows = Database::all('SELECT `k`,`v` FROM `setting`');
            self::$cache = [];
            foreach ($rows as $r) {
                self::$cache[$r['k']] = $r['v'];
            }
        }
        return self::$cache;
    }

    public static function get(string $k, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($k, $all) && $all[$k] !== null ? $all[$k] : $default;
    }

    public static function set(string $k, mixed $v): void
    {
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        Database::run(
            'INSERT INTO `setting` (`k`,`v`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `v`=:v2',
            ['k' => $k, 'v' => (string) $v, 'v2' => (string) $v]
        );
        self::all(true);
    }

    /** 逗号分隔 -> 数组 */
    public static function list(string $k, string $default = ''): array
    {
        $v = (string) self::get($k, $default);
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }

    /**
     * 取整数配置，**空值按默认值处理**。
     *
     * 为什么不直接用 `(int) Setting::get($k, $default)`：
     *   设置页是「整表回传」，某个数值字段如果本来没种过种子、或者被用户清空，
     *   库里会存成空字符串。此时 `Setting::get()` 返回的是 `''` 而不是默认值
     *   （因为键存在、只是值为空），`(int) ''` 得到 0 —— 于是「抓取间隔」这类
     *   配置会静默变成 0，下游 `max(60, 0 * 60)` 兜成 60 秒。
     *   本项目的抓取间隔就这么踩过：设置页显示空、实际按 1 分钟抓。
     *
     * @param array<int,string> $allow 可选白名单，不在其中则回退默认值
     */
    public static function int(string $k, int $default, array $allow = []): int
    {
        $raw = self::get($k, null);
        if ($raw === null || is_array($raw) || trim((string) $raw) === '') {
            return $default;
        }
        if (!is_numeric(trim((string) $raw))) {
            return $default;
        }
        $v = (int) trim((string) $raw);
        if ($allow !== [] && !in_array($v, $allow, true)) {
            return $default;
        }
        return $v;
    }

    /** 取布尔配置（'1'/'true'/'on'/'yes' 视为真） */
    public static function bool(string $k, bool $default = false): bool
    {
        $raw = self::get($k, null);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true);
    }
}
