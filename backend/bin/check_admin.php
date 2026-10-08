<?php
declare(strict_types=1);

/**
 * 管理员账号自检：打印账号元信息并实测登录。
 *   php bin/check_admin.php 用户名 [待验证密码]
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

$username = $argv[1] ?? 'zeng';
$probe    = $argv[2] ?? null;

$u = Database::one(
    'SELECT `id`, `username`, `email`, `role`, `status`, `password_hash` FROM `user` WHERE `username` = :u LIMIT 1',
    ['u' => $username]
);

if ($u === null) {
    exit("账号不存在：{$username}\n");
}

echo "  账号　：#{$u['id']}  {$u['username']}  <{$u['email']}>\n";
echo "  角色　：{$u['role']}  状态：{$u['status']}（1=正常）\n";
echo "  哈希　：{$u['password_hash']}（长度 " . strlen((string) $u['password_hash']) . "）\n";

if ($probe === null) {
    exit;
}

$hit = Auth::checkPassword($probe, (string) $u['password_hash']);
echo "  校验　：" . ($hit ? 'PASS 密码匹配' : 'FAIL 密码不匹配') . "\n";
