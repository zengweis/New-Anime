#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 创建或重置管理员账号。
 *   php bin/init_admin.php                                    # 使用默认 admin / 随机密码
 *   php bin/init_admin.php 名字 邮箱 密码                      # 密码至少 8 位
 *   php bin/init_admin.php 名字 邮箱 密码 --force              # 跳过长度校验（显式指定短密码）
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

// --force 可出现在任意位置；剔除后位置参数顺序不变
$args   = array_slice($argv, 1);
$force  = in_array('--force', $args, true);
$args   = array_values(array_filter($args, static fn($a) => $a !== '--force'));

$username = $args[0] ?? 'admin';
$email    = $args[1] ?? 'admin@example.com';
$password = $args[2] ?? null;

if ($password === null) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < 14; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $generated = true;
} else {
    $generated = false;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("邮箱格式不正确：{$email}\n");
}
if (strlen($password) < 8 && !$force) {
    exit("密码至少 8 位（要强制设置短密码请追加 --force）\n");
}

$exist = Database::one('SELECT `id` FROM `user` WHERE `username` = :u OR `email` = :e LIMIT 1', [
    'u' => $username,
    'e' => strtolower($email),
]);

if ($exist !== null) {
    Database::update('user', [
        'username'      => $username,
        'email'         => strtolower($email),
        'password_hash' => Auth::hash($password),
        'role'          => 'admin',
        'status'        => 1,
    ], '`id` = :id', ['id' => (int) $exist['id']]);
    echo "已更新管理员账号 #{$exist['id']}\n";
} else {
    $id = Database::insert('user', [
        'username'      => $username,
        'email'         => strtolower($email),
        'password_hash' => Auth::hash($password),
        'nickname'      => $username,
        'role'          => 'admin',
        'status'        => 1,
    ]);
    echo "已创建管理员账号 #{$id}\n";
}

echo "----------------------------------------\n";
echo "  用户名：{$username}\n";
echo "  邮箱　：{$email}\n";
if ($generated) {
    echo "  密码　：{$password}   <-- 请立即保存\n";
} else {
    echo "  密码　：（你指定的密码）\n";
}
echo "----------------------------------------\n";
