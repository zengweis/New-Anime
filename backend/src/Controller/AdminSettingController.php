<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\S3;
use App\Core\Setting;
use App\Service\MailService;
use App\Service\S3Uploader;
use App\Service\UserService;

/**
 * 后台：系统设置 / SMTP / 用户 / 邀请码 / 邮件队列。
 */
final class AdminSettingController
{
    /** 允许写入的配置项白名单，按分组 */
    private const GROUPS = [
        'site' => ['site_name', 'site_url'],
        'register' => [
            'register_enabled', 'register_invite', 'register_verify_email',
            'register_need_approve', 'register_default_role',
        ],
        'qb' => ['qb_url', 'qb_user', 'qb_pass', 'qb_category', 'download_root', 'qb_pause_after_done'],
        'download' => ['dedupe_by_ep', 'min_resolution', 'hourly_add_limit', 'video_ext', 'auto_fetch', 'fetch_interval_min'],
        'subs' => [
            // 位图字幕怎么处置：渲染图片 / OCR 文本 / 两者 / 只留 .sup
            'subs_bitmap_mode', 'subs_sup_keep',
            // 图片叠加层的出图参数
            'subs_img_format', 'subs_img_quality', 'subs_img_threshold',
            // OCR 依赖
            'subs_ocr_bin', 'subs_tessdata', 'subs_py_bin',
            'ffmpeg_path',
        ],
        'smtp' => ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name'],
        's3' => [
            's3_enabled', 's3_endpoint', 's3_region', 's3_bucket',
            's3_access_key', 's3_secret_key', 's3_prefix',
            's3_public_base', 's3_url_mode', 's3_auto_upload',
            's3_delete_local', 's3_require_remux', 's3_upload_limit',
        ],
    ];

    /** 需要脱敏的字段 */
    private const SECRETS = ['qb_pass', 'smtp_pass', 's3_secret_key'];

    /**
     * 不允许被空值覆盖的关键字段。
     * 前端设置页是「整表回传」，某个字段被清空会导致服务静默失效（如 SMTP 发不出信），
     * 所以这些字段收到空值时跳过，而不是写空。
     */
    private const NOT_EMPTY = [
        'site_url', 'site_name',
        'qb_url', 'qb_user', 'qb_category', 'download_root',
        'smtp_host', 'smtp_user', 'smtp_from',
        // 数值型：空值没有意义，被清空会导致下游按 0 处理（抓取间隔就踩过这个坑）
        'fetch_interval_min', 'hourly_add_limit',
    ];

    private const MASK = '__SAVED__';

    // ==================================================================
    //  设置
    // ==================================================================

    /** GET /api/admin/settings */
    public function getSettings(): void
    {
        $out = [];
        foreach (self::GROUPS as $group => $keys) {
            foreach ($keys as $k) {
                $v = Setting::get($k, '');
                if (in_array($k, self::SECRETS, true)) {
                    $out[$k] = ($v !== '' && $v !== null) ? self::MASK : '';
                } else {
                    $out[$k] = $v;
                }
            }
        }
        Response::ok(['settings' => $out, 'groups' => array_keys(self::GROUPS)]);
    }

    /** PUT /api/admin/settings */
    public function saveSettings(): void
    {
        $body = Request::body();
        $saved = [];

        foreach (self::GROUPS as $keys) {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $body)) {
                    continue;
                }
                $v = $body[$k];

                // 密码字段：留空或掩码都表示"不修改"
                if (in_array($k, self::SECRETS, true)) {
                    if ($v === '' || $v === self::MASK) {
                        continue;
                    }
                }

                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                } elseif (is_array($v)) {
                    $v = implode(',', array_map('strval', $v));
                }

                $v = trim((string) $v);

                // 关键字段为空的，视为误清空，跳过（不覆盖原值）
                if ($v === '' && in_array($k, self::NOT_EMPTY, true)) {
                    continue;
                }

                if ($k === 'site_url') {
                    $v = rtrim($v, '/');
                }
                if ($k === 'register_default_role' && !in_array($v, ['user', 'admin'], true)) {
                    $v = 'user';
                }
                if ($k === 'smtp_secure' && !in_array($v, ['ssl', 'tls', 'none'], true)) {
                    $v = 'ssl';
                }
                if ($k === 'smtp_port') {
                    $v = (string) max(1, min(65535, (int) $v));
                }
                if ($k === 's3_url_mode' && !in_array($v, ['public', 'presigned'], true)) {
                    $v = 'public';
                }
                if ($k === 's3_upload_limit') {
                    $v = (string) max(1, min(20, (int) $v));
                }
                if ($k === 'fetch_interval_min') {
                    // 抓取间隔：1~1440 分钟。前端 type=number 也可能被贴进非数字
                    $v = (string) max(1, min(1440, (int) $v));
                }
                if ($k === 'hourly_add_limit') {
                    $v = (string) max(1, min(500, (int) $v));
                }
                if ($k === 'subs_sup_keep') {
                    // 位图字幕 .sup 的保留策略：primary 只留要识别的那条 / all 全留 / none 不留
                    $v = in_array($v, ['primary', 'all', 'none'], true) ? $v : 'primary';
                }
                if ($k === 's3_region' && $v === '') {
                    $v = 'auto';
                }
                if ($k === 's3_prefix') {
                    $v = trim($v, '/');
                }
                if (in_array($k, ['s3_endpoint', 's3_public_base'], true) && $v !== '') {
                    // 允许只填域名，统一补上协议
                    if (!preg_match('#^https?://#i', $v)) {
                        $v = 'https://' . $v;
                    }
                    $v = rtrim($v, '/');
                }

                Setting::set($k, $v);
                $saved[] = $k;
            }
        }

        Response::ok(['saved' => $saved], '设置已保存');
    }

    /** POST /api/admin/settings/smtp-test */
    public function smtpTest(): void
    {
        $to = Request::str('email') ?: (string) (Auth::user()['email'] ?? '');
        if ($to === '') {
            Response::fail('请填写收件邮箱');
        }
        $r = MailService::sendTest($to);
        $r['ok'] ? Response::ok(['trace' => $r['trace']], $r['msg']) : Response::fail($r['msg']);
    }

    // ==================================================================
    //  对象存储（S3 / R2）
    // ==================================================================

    /**
     * POST /api/admin/settings/s3-test
     *
     * 直接读 setting 表里的当前配置去连（不要求先开启 s3_enabled），
     * 这样「先测试再启用」的流程才走得通。
     */
    public function s3Test(): void
    {
        $s3 = S3::fromSettings();
        if ($s3 === null) {
            Response::fail(S3::configHint() ?: '对象存储配置不完整');
        }

        $r = $s3->test(Request::bool('write', true));

        Response::json([
            'code' => $r['ok'] ? 0 : 1,
            'msg'  => $r['msg'],
            'data' => ['detail' => $r['detail'], 'public_base' => S3::publicBase()],
        ], 200);
    }

    /** GET /api/admin/settings/s3-stats —— 队列与用量概况 */
    public function s3Stats(): void
    {
        $stats = S3Uploader::stats();
        $stats['enabled']     = S3::enabled();
        $stats['config_hint'] = S3::configHint();
        $stats['public_base'] = S3::publicBase();
        $stats['prefix']      = S3::prefix();
        $stats['url_mode']    = (string) Setting::get('s3_url_mode', 'public');
        $stats['max_attempts'] = S3Uploader::MAX_ATTEMPTS;

        Response::ok($stats);
    }

    // ==================================================================
    //  用户
    // ==================================================================

    /** GET /api/admin/users */
    public function users(): void
    {
        $page   = max(1, Request::int('page', 1));
        $per    = min(100, max(1, Request::int('per_page', 30)));
        $off    = ($page - 1) * $per;
        $status = Request::int('status', -1);
        $kw     = Request::str('keyword');

        $where = ['1=1'];
        $bind  = [];
        if ($status >= 0) {
            $where[] = '`status` = :st';
            $bind['st'] = $status;
        }
        if ($kw !== '') {
            $where[] = '(`username` LIKE :kw OR `email` LIKE :kw OR `nickname` LIKE :kw)';
            $bind['kw'] = '%' . $kw . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::val("SELECT COUNT(*) FROM `user` WHERE {$whereSql}", $bind);
        $rows  = Database::all(
            "SELECT `id`,`username`,`email`,`nickname`,`role`,`status`,`reg_ip`,
                    `last_login`,`last_ip`,`created_at`
             FROM `user` WHERE {$whereSql}
             ORDER BY `id` DESC LIMIT {$per} OFFSET {$off}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['status']    = (int) $r['status'];
            $r['status_cn'] = self::userStatusCn($r['status']);
            unset($r['reg_ip'], $r['last_ip']);
        }
        unset($r);

        Response::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** POST /api/admin/users/{id}/status —— 审批 / 封禁 */
    public function setUserStatus(array $args): void
    {
        $id     = (int) ($args['id'] ?? 0);
        $status = Request::int('status', -1);
        if (!in_array($status, [0, 1, 2, 9], true)) {
            Response::fail('非法状态');
        }
        $target = Database::one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $id]);
        if ($target === null) {
            Response::notFound('用户不存在');
        }
        // 不允许把自己封了
        if ($id === Auth::id() && $status !== 1) {
            Response::fail('不能修改自己的状态');
        }

        Database::update('user', ['status' => $status], '`id` = :id', ['id' => $id]);

        // 审核通过发通知邮件
        if ($status === 1 && (int) $target['status'] === 2) {
            MailService::accountApproved($target);
        }

        Response::ok(null, match ($status) {
            1 => '已放行',
            2 => '已置为待审核',
            9 => '已禁用',
            default => '已置为待验证',
        });
    }

    /** POST /api/admin/users/{id}/role */
    public function setUserRole(array $args): void
    {
        $id   = (int) ($args['id'] ?? 0);
        $role = Request::str('role');
        if (!in_array($role, ['user', 'admin'], true)) {
            Response::fail('非法角色');
        }
        if ($id === Auth::id()) {
            Response::fail('不能修改自己的角色');
        }
        Database::update('user', ['role' => $role], '`id` = :id', ['id' => $id]);
        Response::ok(null, '角色已更新');
    }

    /** POST /api/admin/users —— 管理员直接开号 */
    public function createUser(): void
    {
        $username = Request::str('username');
        $email    = strtolower(Request::str('email'));
        $password = (string) Request::input('password', '');
        $role     = Request::str('role') === 'admin' ? 'admin' : 'user';

        if (!preg_match('/^[\p{Han}A-Za-z][\p{Han}A-Za-z0-9_\-]{1,19}$/u', $username)) {
            Response::fail('用户名格式不正确');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::fail('邮箱格式不正确');
        }
        if (($err = UserService::checkPasswordStrength($password)) !== null) {
            Response::fail($err);
        }
        if (Database::val('SELECT 1 FROM `user` WHERE `username` = :u OR `email` = :e', ['u' => $username, 'e' => $email]) !== null) {
            Response::fail('用户名或邮箱已存在');
        }

        $id = Database::insert('user', [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => Auth::hash($password),
            'nickname'      => Request::str('nickname') ?: $username,
            'role'          => $role,
            'status'        => 1,
            'reg_ip'        => Request::ip(),
        ]);
        Response::ok(['id' => $id], '账号已创建');
    }

    /** DELETE /api/admin/users/{id} */
    public function deleteUser(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id === Auth::id()) {
            Response::fail('不能删除自己');
        }
        Database::run('DELETE FROM `user` WHERE `id` = :id', ['id' => $id]);
        Database::run('DELETE FROM `user_favorite` WHERE `user_id` = :id', ['id' => $id]);
        Database::run('DELETE FROM `user_history` WHERE `user_id` = :id', ['id' => $id]);
        Response::ok(null, '已删除');
    }

    // ==================================================================
    //  邀请码
    // ==================================================================

    /** GET /api/admin/invites */
    public function invites(): void
    {
        $rows = Database::all(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM `user` u WHERE u.`invite_code_id` = c.`id`) AS `used_real`,
                    u2.`username` AS `last_user`
             FROM `invite_code` c
             LEFT JOIN `user` u2 ON u2.`invite_code_id` = c.`id`
             ORDER BY c.`id` DESC LIMIT 500'
        );

        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['id']])) {
                continue;
            }
            $seen[$r['id']] = true;
            $r['id']         = (int) $r['id'];
            $r['max_uses']   = (int) $r['max_uses'];
            $r['used_count'] = (int) $r['used_count'];
            $r['used_real']  = (int) $r['used_real'];
            $r['status']     = (int) $r['status'];
            $r['expired']    = $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < time();
            $out[] = $r;
        }

        Response::ok(['list' => $out]);
    }

    /** POST /api/admin/invites/batch —— 批量生成 */
    public function createInvites(): void
    {
        $count   = Request::int('count', 5);
        $maxUses = Request::int('max_uses', 1);
        $days    = Request::int('expire_days', 0);
        $note    = Request::str('note') ?: null;

        $codes = UserService::createInvites(
            max(1, min(200, $count)),
            max(1, min(100, $maxUses)),
            $days > 0 ? $days : null,
            $note,
            Auth::id() ?: null
        );

        Response::ok(['codes' => $codes, 'count' => count($codes)], '已生成 ' . count($codes) . ' 个邀请码');
    }

    /** POST /api/admin/invites/{id}/toggle */
    public function toggleInvite(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $row = Database::one('SELECT `status` FROM `invite_code` WHERE `id` = :id', ['id' => $id]);
        if ($row === null) {
            Response::notFound('邀请码不存在');
        }
        $next = (int) $row['status'] === 1 ? 0 : 1;
        Database::update('invite_code', ['status' => $next], '`id` = :id', ['id' => $id]);
        Response::ok(['status' => $next], $next === 1 ? '已启用' : '已作废');
    }

    /** DELETE /api/admin/invites/{id} */
    public function deleteInvite(array $args): void
    {
        Database::run('DELETE FROM `invite_code` WHERE `id` = :id', ['id' => (int) ($args['id'] ?? 0)]);
        Response::ok(null, '已删除');
    }

    // ==================================================================
    //  邮件队列
    // ==================================================================

    /** GET /api/admin/mails */
    public function mails(): void
    {
        $rows = Database::all(
            'SELECT `id`,`to_email`,`to_name`,`subject`,`status`,`retry`,`error`,`created_at`,`sent_at`
             FROM `mail_queue` ORDER BY `id` DESC LIMIT 100'
        );
        Response::ok([
            'list'   => $rows,
            'todo'   => (int) Database::val('SELECT COUNT(*) FROM `mail_queue` WHERE `status` = 0'),
            'failed' => (int) Database::val('SELECT COUNT(*) FROM `mail_queue` WHERE `status` = 2'),
        ]);
    }

    /** POST /api/admin/mails/retry —— 重投失败邮件 */
    public function retryMails(): void
    {
        $n = Database::run('UPDATE `mail_queue` SET `status` = 0, `retry` = 0, `error` = NULL WHERE `status` = 2')->rowCount();
        Response::ok(['count' => $n], "已重置 {$n} 封邮件");
    }

    // ------------------------------------------------------------------

    private static function userStatusCn(int $s): string
    {
        return match ($s) {
            0 => '待验证邮箱',
            1 => '正常',
            2 => '待审核',
            9 => '已禁用',
            default => '未知',
        };
    }
}
