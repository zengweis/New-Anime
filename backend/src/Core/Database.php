<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * PDO 单例 + 便捷查询方法。
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = cfg('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['name'], $c['charset']
        );
        try {
            // 字符集由 DSN 的 charset 指定，无需再下发 SET NAMES
            // （PDO::MYSQL_ATTR_INIT_COMMAND 自 PHP 8.5 起已废弃）
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('数据库连接失败: ' . $e->getMessage(), (int) $e->getCode());
        }
        return self::$pdo;
    }

    /** 执行并返回 PDOStatement */
    public static function run(string $sql, array $bind = []): \PDOStatement
    {
        [$sql, $params] = self::expand($sql, $bind);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * 把命名占位符统一展开成位置占位符。
     *
     * 背景：在 ATTR_EMULATE_PREPARES = false（原生预处理）下，PDO 不允许同一个
     * 命名占位符出现多次，`title LIKE :kw OR alias LIKE :kw` 这类写法会抛
     * HY093 Invalid parameter number。这里在交给驱动之前自行展开，
     * 既支持占位符复用，又保留原生预处理的注入防护。
     *
     * 同时忽略 SQL 中不存在的多余绑定键（只记日志），避免 500。
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function expand(string $sql, array $bind): array
    {
        if ($bind === [] || !str_contains($sql, ':')) {
            return [$sql, array_map([self::class, 'scalarize'], array_values($bind))];
        }

        $out    = '';
        $params = [];
        $used   = [];
        $len    = strlen($sql);
        $quote  = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            // 字符串 / 标识符内部原样输出，避免误伤 '12:30' 这类字面量
            if ($quote !== '') {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $out .= $sql[++$i];
                } elseif ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $out .= $sql[++$i];      // '' 转义
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                continue;
            }

            // `::` 原样保留，不被当成占位符
            if ($ch === ':' && $i > 0 && $sql[$i - 1] === ':') {
                $out .= $ch;
                continue;
            }

            if ($ch === ':' && preg_match('/[A-Za-z_]/', $sql[$i + 1] ?? '')) {
                $j = $i + 1;
                while ($j < $len && preg_match('/[A-Za-z0-9_]/', $sql[$j])) {
                    $j++;
                }
                $name = substr($sql, $i + 1, $j - $i - 1);

                if (array_key_exists($name, $bind)) {
                    $out        .= '?';
                    $params[]    = self::scalarize($bind[$name]);
                    $used[$name] = true;
                } else {
                    $out .= ':' . $name;     // 缺参数：交给 PDO 报错，便于定位
                }
                $i = $j - 1;
                continue;
            }

            $out .= $ch;
        }

        if (($extra = array_diff(array_keys($bind), array_keys($used))) !== []) {
            Log::write('sql', '忽略 SQL 中不存在的多余绑定参数', [
                'extra' => array_values($extra),
                'sql'   => $out,
            ]);
        }

        return [$out, $params];
    }

    /** 归一化绑定量：bool → int，时间对象 → 字符串 */
    private static function scalarize(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        return $v;
    }

    /** 取多行 */
    public static function all(string $sql, array $bind = []): array
    {
        return self::run($sql, $bind)->fetchAll();
    }

    /** 取一行 */
    public static function one(string $sql, array $bind = []): ?array
    {
        $row = self::run($sql, $bind)->fetch();
        return $row === false ? null : $row;
    }

    /** 取单值 */
    public static function val(string $sql, array $bind = [], mixed $default = null): mixed
    {
        $v = self::run($sql, $bind)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** 插入，返回自增 ID */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table, implode('`,`', $cols), implode(',', $ph)
        );
        self::run($sql, self::bindable($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** 更新，返回影响行数 */
    public static function update(string $table, array $data, string $where, array $bind = []): int
    {
        $sets = implode(',', array_map(fn($c) => "`{$c}`=:{$c}", array_keys($data)));
        $sql  = "UPDATE `{$table}` SET {$sets} WHERE {$where}";
        return self::run($sql, array_merge(self::bindable($data), $bind))->rowCount();
    }

    /** 把 bool/array 转成 PDO 能吃的标量 */
    private static function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 1 : 0;
            } elseif (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    public static function beginTransaction(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
