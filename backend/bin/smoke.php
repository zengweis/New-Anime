#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 部署后自检：把整条链路挨个验一遍。
 *   php bin/smoke.php
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Core\Mailer;
use App\Core\Setting;
use App\Service\MatchEngine;
use App\Service\Qbittorrent;
use App\Service\TitleParser;

$pass = 0;
$fail = 0;
$warn = 0;

function section(string $t): void
{
    echo "\n\033[1m{$t}\033[0m\n";
}

function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true || $r === null) {
            echo "  \033[32m[ OK ]\033[0m {$name}\n";
            $pass++;
        } else {
            echo "  \033[31m[FAIL]\033[0m {$name} — {$r}\n";
            $fail++;
        }
    } catch (Throwable $e) {
        echo "  \033[31m[FAIL]\033[0m {$name} — {$e->getMessage()}\n";
        $fail++;
    }
}

function warn(string $name, string $msg): void
{
    global $warn;
    echo "  \033[33m[WARN]\033[0m {$name} — {$msg}\n";
    $warn++;
}

echo "\033[1m===== anime-new 自检 =====\033[0m\n";

// ------------------------------------------------------------------
section('运行环境');
check('PHP 版本 >= 8.1', static fn() => version_compare(PHP_VERSION, '8.1', '>=')
    ? true : '当前 ' . PHP_VERSION);

foreach (['pdo_mysql', 'mbstring', 'curl', 'dom', 'json', 'openssl'] as $ext) {
    check("扩展 {$ext}", static fn() => extension_loaded($ext) ? true : '未安装');
}

$root = (string) Setting::get('download_root', '/data/downloads/anime');
check('下载目录存在', static fn() => is_dir($root) ? true : "不存在: {$root}");
check('下载目录可读', static fn() => is_readable($root) ? true : "不可读: {$root}");
if (!is_writable($root)) {
    warn('下载目录可写', "{$root} 对当前用户不可写（qBittorrent 若以别的用户运行则无妨）");
}

$free = @disk_free_space($root);
if ($free !== false) {
    $g = round($free / 1073741824, 1);
    if ($g < 5) {
        warn('剩余磁盘', "仅剩 {$g} GB，请留意清理");
    } else {
        echo "  \033[32m[ OK ]\033[0m 剩余磁盘 {$g} GB\n";
        $pass++;
    }
}

// ------------------------------------------------------------------
section('数据库');
check('PDO 连接', static function () {
    Database::val('SELECT 1');
    return true;
});

$tables = ['user', 'anime', 'episode', 'rss_feed', 'rss_item', 'match_rule',
    'setting', 'job_log', 'invite_code', 'email_token', 'mail_queue',
    'user_favorite', 'user_history'];
check('13 张表齐全', static function () use ($tables) {
    $have = array_column(Database::all('SHOW TABLES'), array_key_first(Database::one('SHOW TABLES')));
    $miss = array_diff($tables, $have);
    return $miss ? '缺少: ' . implode(',', $miss) : true;
});

check('存在管理员账号', static fn() => (int) Database::val(
    "SELECT COUNT(*) FROM `user` WHERE `role` = 'admin' AND `status` = 1"
) > 0 ? true : '没有可用的管理员');

echo "  · 用户 " . Database::val('SELECT COUNT(*) FROM `user`')
    . " · 番剧 " . Database::val('SELECT COUNT(*) FROM `anime`')
    . " · 集数 " . Database::val('SELECT COUNT(*) FROM `episode`')
    . " · RSS条目 " . Database::val('SELECT COUNT(*) FROM `rss_item`')
    . " · 规则 " . Database::val('SELECT COUNT(*) FROM `match_rule`') . "\n";

// ------------------------------------------------------------------
section('qBittorrent');
check('WebUI 可达', static function () {
    $v = (new Qbittorrent())->version();
    return $v !== '' ? true : '没有返回版本号';
});

check('分类可创建', static function () {
    (new Qbittorrent())->ensureCategory((string) Setting::get('qb_category', 'anime'));
    return true;
});

// ------------------------------------------------------------------
section('dmhy RSS');
check('订阅源已配置', static function () {
    $n = (int) Database::val('SELECT COUNT(*) FROM `rss_feed` WHERE `enabled` = 1');
    return $n > 0 ? true : '没有启用的订阅源，执行 php bin/seed.php';
});

check('条目已抓取', static function () {
    $n = (int) Database::val('SELECT COUNT(*) FROM `rss_item`');
    return $n > 0 ? true : '库里还没有条目，执行 php bin/fetch_rss.php';
});

// ------------------------------------------------------------------
section('标题解析');
$samples = [
    ['[ANi] Kami no Shizuku / 神之雫 - 23 [1080P][Baha][CHT]', 'ANi', 23.0, '1080P', '繁'],
    ['[喵萌奶茶屋][4月新番] 某番剧 / Some Anime [07][1080P][简繁双语]', '喵萌奶茶屋', 7.0, '1080P', '简繁'],
    ['[豌豆字幕组] 关于我转生变成史莱姆这档事 - 24.5 [1080P][简日双语]', '豌豆字幕组', 24.5, '1080P', '简'],
    ['[桜都字幕组][某番剧][第12话][1080p][简体内嵌]', '桜都字幕组', 12.0, '1080P', '简'],
];
foreach ($samples as $i => $s) {
    check('样例 ' . ($i + 1) . ' ' . mb_substr($s[0], 0, 34), static function () use ($s) {
        $p = TitleParser::parse($s[0]);
        $errs = [];
        if ($p['subgroup'] !== $s[1]) {
            $errs[] = "组={$p['subgroup']} 期望 {$s[1]}";
        }
        if ((float) $p['ep_no'] !== $s[2]) {
            $errs[] = "集={$p['ep_no']} 期望 {$s[2]}";
        }
        if ($p['resolution'] !== $s[3]) {
            $errs[] = "分辨率={$p['resolution']} 期望 {$s[3]}";
        }
        if ($p['subtitle_lang'] !== $s[4]) {
            $errs[] = "字幕={$p['subtitle_lang']} 期望 {$s[4]}";
        }
        if ($p['title_key'] === '') {
            $errs[] = '主标题为空';
        }
        return $errs ? implode(' / ', $errs) : true;
    });
}

check('真实条目主标题无空值', static function () {
    $rows = Database::all('SELECT `title` FROM `rss_item` LIMIT 300');
    $empty = 0;
    foreach ($rows as $r) {
        if (TitleParser::parse((string) $r['title'])['title_key'] === '') {
            $empty++;
        }
    }
    return $empty === 0 ? true : "{$empty} 条解析为空";
});

// ------------------------------------------------------------------
section('匹配引擎（事务内试跑，结束后回滚）');
check('临时规则能命中真实条目', static function () {
    Database::beginTransaction();
    try {
        $aid = Database::insert('anime', [
            'title' => '__SMOKE__',
            'year'  => 2099,
        ]);
        Database::insert('match_rule', [
            'anime_id'   => $aid,
            'pattern'    => '史莱姆|Slime',
            'flags'      => 'iu',
            'ep_capture' => 0,
            'priority'   => 99,
            'enabled'    => 1,
        ]);
        MatchEngine::flush();

        $hit   = 0;
        $first = '';
        foreach (Database::all('SELECT `title` FROM `rss_item` LIMIT 600') as $r) {
            if (MatchEngine::match((string) $r['title']) !== null) {
                $hit++;
                if ($first === '') {
                    $first = mb_substr((string) $r['title'], 0, 52);
                }
            }
        }
        if ($hit > 0) {
            echo "         ↳ 命中 {$hit} 条，示例：{$first}\n";
        }
        return $hit > 0 ? true : '一条都没命中（可能是库里没有相关番）';
    } finally {
        Database::rollback();
        MatchEngine::flush();
    }
});

check('非法正则被拦截', static function () {
    return MatchEngine::validate('([unclosed', 'iu') !== null ? true : '未拦截非法正则';
});

check('preview 接口可用', static function () {
    $r = MatchEngine::preview('1080P', 'iu', 50, 1);
    return isset($r['hit'], $r['items']) ? true : '返回结构异常';
});

// ------------------------------------------------------------------
section('SMTP 邮件');
$smtpHost = (string) Setting::get('smtp_host', '');
if ($smtpHost === '') {
    warn('SMTP 未配置', '后台「系统设置 → 邮件」里填好后，注册验证信才能发出去');
} else {
    check('SMTP 可连接', static function () {
        $m = Mailer::fromSetting();
        $m->connect();
        $m->close();
        return true;
    });
}

echo "  待发邮件 " . Database::val('SELECT COUNT(*) FROM `mail_queue` WHERE `status` = 0')
    . " · 失败 " . Database::val('SELECT COUNT(*) FROM `mail_queue` WHERE `status` = 2') . "\n";

// ------------------------------------------------------------------
echo "\n\033[1m===== 结果 =====\033[0m\n";
printf("  通过 %d · 失败 %d", $pass, $fail);
if ($warn > 0) {
    printf(" · 警告 %d", $warn);
}
echo "\n\n";

exit($fail > 0 ? 1 : 0);
