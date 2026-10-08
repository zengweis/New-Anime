<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Setting;

/**
 * 邮件模板 + 异步队列。
 * 注册/找回等接口只入队，实际发送交给 bin/send_mail.php（cron 每分钟）。
 */
final class MailService
{
    public static function queue(string $to, string $name, string $subject, string $html): int
    {
        return Database::insert('mail_queue', [
            'to_email' => $to,
            'to_name'  => mb_substr($name, 0, 64),
            'subject'  => mb_substr($subject, 0, 255),
            'body'     => $html,
        ]);
    }

    // ------------------------------------------------------------------
    //  业务邮件
    // ------------------------------------------------------------------

    public static function verifyEmail(array $user, string $rawToken): void
    {
        $url  = self::siteUrl() . '/verify-email?token=' . $rawToken;
        $site = self::siteName();
        $name = self::displayName($user);

        $html = self::wrap('验证你的邮箱', '
            <p style="margin:0 0 14px">' . self::e($name) . '，欢迎加入 ' . self::e($site) . '。</p>
            <p style="margin:0 0 22px">点击下面的按钮完成邮箱验证，链接 <strong>24 小时</strong>内有效。</p>
            ' . self::button('验证邮箱', $url) . '
            <p style="margin:22px 0 6px;color:#6b7280;font-size:13px">如果按钮点不开，把下面的链接复制到浏览器：</p>
            <p style="margin:0;word-break:break-all;color:#185FA5;font-size:13px">' . self::e($url) . '</p>
        ');

        self::queue((string) $user['email'], $name, "【{$site}】验证你的邮箱", $html);
    }

    public static function passwordReset(array $user, string $rawToken): void
    {
        $url  = self::siteUrl() . '/reset-password?token=' . $rawToken;
        $site = self::siteName();
        $name = self::displayName($user);

        $html = self::wrap('重置密码', '
            <p style="margin:0 0 14px">' . self::e($name) . '，我们收到了重置密码的请求。</p>
            <p style="margin:0 0 22px">点击下面的按钮设置新密码，链接 <strong>1 小时</strong>内有效。若非本人操作，请忽略这封邮件。</p>
            ' . self::button('重置密码', $url) . '
            <p style="margin:22px 0 6px;color:#6b7280;font-size:13px">如果按钮点不开，把下面的链接复制到浏览器：</p>
            <p style="margin:0;word-break:break-all;color:#185FA5;font-size:13px">' . self::e($url) . '</p>
        ');

        self::queue((string) $user['email'], $name, "【{$site}】重置密码", $html);
    }

    public static function accountApproved(array $user): void
    {
        $site = self::siteName();
        $html = self::wrap('账号已通过审核', '
            <p style="margin:0 0 14px">' . self::e(self::displayName($user)) . '，你的账号已通过管理员审核。</p>
            <p style="margin:0">现在可以登录 ' . self::e($site) . ' 了。</p>
        ');
        self::queue((string) $user['email'], self::displayName($user), "【{$site}】账号已通过审核", $html);
    }

    // ------------------------------------------------------------------
    //  发送
    // ------------------------------------------------------------------

    /** 后台"发送测试邮件"用，同步发 */
    public static function sendTest(string $to): array
    {
        try {
            $m = Mailer::fromSetting();
            $site = self::siteName();
            $html = self::wrap('SMTP 测试', '
                <p style="margin:0 0 14px">这是一封来自 ' . self::e($site) . ' 的测试邮件。</p>
                <p style="margin:0">如果你收到了它，说明 SMTP 配置正确。</p>
            ');
            $m->send($to, '', "【{$site}】SMTP 测试邮件", $html);
            return ['ok' => true, 'msg' => '测试邮件已发送', 'trace' => $m->trace()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage(), 'trace' => []];
        }
    }

    /**
     * 消费队列（cron 调用）。
     * @return array{sent:int,failed:int,remain:int}
     */
    public static function processQueue(int $limit = 30): array
    {
        $rows = Database::all(
            'SELECT * FROM `mail_queue`
             WHERE `status` = 0 AND `retry` < 3
             ORDER BY `id` ASC LIMIT ' . max(1, $limit)
        );

        $stat = ['sent' => 0, 'failed' => 0, 'remain' => 0];
        if (!$rows) {
            return $stat;
        }

        $mailer = Mailer::fromSetting();
        $dead   = false;

        foreach ($rows as $r) {
            if ($dead) {
                $stat['remain']++;
                continue;
            }
            try {
                $mailer->send(
                    (string) $r['to_email'],
                    (string) ($r['to_name'] ?? ''),
                    (string) $r['subject'],
                    (string) $r['body']
                );
                Database::update('mail_queue', [
                    'status'  => 1,
                    'sent_at' => date('Y-m-d H:i:s'),
                    'error'   => null,
                ], '`id` = :id', ['id' => (int) $r['id']]);
                $stat['sent']++;
            } catch (\Throwable $e) {
                $retry = (int) $r['retry'] + 1;
                Database::update('mail_queue', [
                    'status' => $retry >= 3 ? 2 : 0,
                    'retry'  => $retry,
                    'error'  => mb_substr($e->getMessage(), 0, 480),
                ], '`id` = :id', ['id' => (int) $r['id']]);
                $stat['failed']++;
                // 首封就失败，多半是配置问题，本轮不再继续
                if ($stat['sent'] === 0) {
                    $dead = true;
                    Log::write('mail', 'SMTP 发送失败，中止本轮: ' . $e->getMessage());
                }
            }
        }

        try {
            $mailer->close();
        } catch (\Throwable) {
            // 忽略
        }

        return $stat;
    }

    // ------------------------------------------------------------------
    //  模板
    // ------------------------------------------------------------------

    private static function wrap(string $title, string $inner): string
    {
        $site = self::siteName();
        return '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f5f7fa;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif;color:#1f2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="520" cellpadding="0" cellspacing="0" '
            . 'style="border-collapse:collapse;background:#ffffff;border-radius:12px;'
            . 'border:1px solid #e5e7eb;overflow:hidden">'
            . '<tr><td style="padding:20px 28px;border-bottom:1px solid #eef1f5">'
            . '<span style="font-size:15px;font-weight:600;color:#0C447C">' . self::e($site) . '</span>'
            . '</td></tr>'
            . '<tr><td style="padding:28px">'
            . '<h1 style="margin:0 0 18px;font-size:17px;font-weight:600;color:#111827">' . self::e($title) . '</h1>'
            . $inner
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#fafbfc;border-top:1px solid #eef1f5;'
            . 'color:#9ca3af;font-size:12px">'
            . '本邮件由系统自动发送，请勿直接回复。'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function button(string $text, string $url): string
    {
        return '<a href="' . self::e($url) . '" style="display:inline-block;padding:11px 26px;'
            . 'background:#185FA5;color:#ffffff;text-decoration:none;border-radius:8px;'
            . 'font-size:14px;font-weight:500">' . self::e($text) . '</a>';
    }

    // ------------------------------------------------------------------

    public static function siteName(): string
    {
        return (string) Setting::get('site_name', 'Anime New');
    }

    public static function siteUrl(): string
    {
        return rtrim((string) Setting::get('site_url', ''), '/');
    }

    private static function displayName(array $u): string
    {
        return (string) ($u['nickname'] ?? '') !== '' ? (string) $u['nickname'] : (string) ($u['username'] ?? '');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
