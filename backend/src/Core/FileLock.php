<?php
declare(strict_types=1);

namespace App\Core;

/**
 * flock 锁文件的打开工具。
 *
 * 为什么不能直接 `fopen($path, 'c')`：
 *   这些锁文件放在**多用户共享**的目录里（下载目录 / 字幕缓存目录）。它们可能被
 *   另一个用户创建过 —— 例如运维从命令行以 root 跑过一次 `bin/extract_subs.php`
 *   或 `bin/s3_upload.php`，于是留下一个 `root:root 0644` 的锁文件。之后以
 *   www-data 常驻的 daemon 再来 `fopen($path, 'c')` 就会失败，因为 'c' 模式需要
 *   **文件可写**。
 *
 *   症状极其隐蔽：调用方只看到「拿不到锁」，于是当成「别人正在跑」直接跳过，
 *   任务从此静默停摆 —— 日志里连一行错误都没有。本项目就踩过：上传任务因此
 *   一次都没执行过，而所有单元测试都是绿的。
 *
 * 所以这里做两件事：
 *   1. 打开成功后 `chmod 0666`，让其他身份的进程也能用；
 *   2. 打不开且文件存在时，**删除重建**（unlink 只需要目录可写，不需要文件属主），
 *      从而自愈历史遗留的错误属主。
 *
 * 关于重建的取舍：理论上存在「把另一个进程正持有的锁删掉」的窗口。但只有在
 * 根本打不开该文件时才会走到这一步 —— 那个文件对当前进程本来就不可用，且
 * flock 是建议锁、持有者不会因此出错。用这点理论风险换取「不静默停摆」是值得的。
 */
final class FileLock
{
    /**
     * @param string|null $error 失败原因（供调用方显性上报，不要静默当 busy）
     * @return resource|null
     */
    public static function open(string $path, ?string &$error = null)
    {
        $error = null;

        $fp = @fopen($path, 'c');
        if ($fp !== false) {
            @chmod($path, 0666);
            return $fp;
        }

        $dir = dirname($path);
        if (is_file($path) && is_writable($dir) && @unlink($path)) {
            $fp = @fopen($path, 'c');
            if ($fp !== false) {
                @chmod($path, 0666);
                Log::write('lock', '删除了不可用的锁文件并重建', ['path' => $path]);
                return $fp;
            }
        }

        $error = '锁文件不可用：' . $path
            . '（目录可写：' . (is_writable($dir) ? '是' : '否')
            . '，文件属主可能不是当前运行用户）';
        return null;
    }

    /**
     * 当前进程能否正常使用该锁文件 —— 供运维自检展示，
     * 避免同类问题再次以「任务静默不跑」的形式出现。
     *
     * @return array{path:string,ok:bool,note:string}
     */
    public static function diagnose(string $path): array
    {
        $err = null;
        $fp = self::open($path, $err);
        if ($fp !== false) {
            fclose($fp);
            $owner = @fileowner($path);
            $name = $owner !== false && function_exists('posix_getpwuid')
                ? (posix_getpwuid($owner)['name'] ?? (string) $owner)
                : (string) ($owner === false ? '?' : $owner);
            return [
                'path' => $path,
                'ok'   => true,
                'note' => '可用（属主 ' . $name . '）',
            ];
        }
        return ['path' => $path, 'ok' => false, 'note' => (string) $err];
    }
}
