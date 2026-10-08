<?php
declare(strict_types=1);

/**
 * anime-new 端到端验收（带鉴权）
 *
 * 用法： php bin/e2e.php [baseUrl]
 *
 * 覆盖：鉴权闸门 → 公开接口 → 管理员接口 → 写操作闭环（建/改/删） → 跨权限拒绝
 * 全部写操作自带清理，不会留脏数据。
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Service\SubtitleService;

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1', '/');

$pass = 0;
$fail = 0;
$failures = [];

/** 发一个 HTTP 请求，返回 [status, decodedData] */
function req(string $method, string $path, array $body = [], ?string $token = null): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== []) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        // 站点启用 HTTPS 后 HTTP 会 301；直接跟随跳转，并跳过自签/IP 访问的证书校验
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    return [$code, is_array($json) ? $json : ['_raw' => $raw]];
}

/** 断言业务码为 0 */
function ok(string $label, string $method, string $path, array $body = [], ?string $token = null, ?callable $check = null): void
{
    global $pass, $fail, $failures;
    [$code, $res] = req($method, $path, $body, $token);
    $biz = $res['code'] ?? -1;

    if ($code === 200 && $biz === 0 && ($check === null || $check($res['data'] ?? null))) {
        $pass++;
        printf("  \033[32mPASS\033[0m  %-46s\n", strtoupper($method) . ' ' . $path);
        return;
    }
    $fail++;
    $failures[] = "$method $path";
    printf("  \033[31mFAIL\033[0m  %-46s HTTP %d code=%s msg=%s\n",
        strtoupper($method) . ' ' . $path, $code, (string) $biz, (string) ($res['msg'] ?? ''));
}

/** 断言指定的 HTTP 状态与业务码 */
function expect(string $label, string $method, string $path, int $wantHttp, int $wantBiz, array $body = [], ?string $token = null): void
{
    global $pass, $fail, $failures;
    [$code, $res] = req($method, $path, $body, $token);
    if ($code === $wantHttp && ($res['code'] ?? -1) === $wantBiz) {
        $pass++;
        printf("  \033[32mPASS\033[0m  %-46s HTTP %d code %d\n",
            strtoupper($method) . ' ' . $path, $wantHttp, $wantBiz);
        return;
    }
    $fail++;
    $failures[] = "$method $path";
    printf("  \033[31mFAIL\033[0m  %-46s 期望 HTTP %d/%d，实得 HTTP %d/%s\n",
        strtoupper($method) . ' ' . $path, $wantHttp, $wantBiz, $code, (string) ($res['code'] ?? '-'));
}

function section(string $t): void
{
    echo "\n\033[36m── $t ──\033[0m\n";
}

/** 只断言 HTTP 状态码：用于返回纯文本而非 JSON 的端点（如字幕分发） */
function expectHttp(string $label, string $method, string $path, int $wantHttp, ?string $token = null): void
{
    global $pass, $fail, $failures;
    [$code] = req($method, $path, [], $token);
    if ($code === $wantHttp) {
        $pass++;
        printf("  \033[32mPASS\033[0m  %-46s HTTP %d\n", strtoupper($method) . ' ' . $path, $wantHttp);
        return;
    }
    $fail++;
    $failures[] = "$method $path";
    printf("  \033[31mFAIL\033[0m  %-46s 期望 HTTP %d，实得 %d\n",
        strtoupper($method) . ' ' . $path, $wantHttp, $code);
}

// =====================================================================
echo "anime-new 端到端验收 @ $BASE\n";

// ---------------------------------------------------------------- 准备
$admin = Database::one('SELECT * FROM `user` WHERE `role` = "admin" AND `status` = 1 ORDER BY `id` LIMIT 1');
if ($admin === null) {
    exit("没有可用的管理员账号，先跑 php bin/init_admin.php\n");
}
$adminToken = Auth::issue((int) $admin['id'], 'admin');
$uid        = (int) $admin['id'];

$STAMP    = date('mdHis');
$TMP_ANIME = '__E2E番剧' . $STAMP . '__';
$TMP_USER  = 'e2e' . $STAMP;
$TMP_MAIL  = "e2e{$STAMP}@example.invalid";

echo "管理员 #{$uid} {$admin['username']} · 测试标记 {$STAMP}\n";

// ---------------------------------------------------------------- 公开
section('公开接口');
ok('health',        'GET', '/api/health', [], null, fn($d) => ($d['status'] ?? '') === 'ok');
ok('auth/config',   'GET', '/api/auth/config', [], null, fn($d) => array_key_exists('enabled', $d));
ok('home',          'GET', '/api/home', [], null, fn($d) => isset($d['stats']));
ok('animes 列表',    'GET', '/api/animes?page=1&per_page=5', [], null, fn($d) => isset($d['total']));
ok('animes keyword', 'GET', '/api/animes?keyword=' . urlencode('番') . '&per_page=5', [], null, fn($d) => isset($d['total']));
ok('animes order=hot', 'GET', '/api/animes?order=hot&per_page=5', [], null, fn($d) => isset($d['total']));
ok('animes/meta',   'GET', '/api/animes/meta', [], null, fn($d) => isset($d['stats']));
ok('search',        'GET', '/api/search?q=a', [], null, fn($d) => isset($d['list']));

// ---------------------------------------------------------------- 闸门
section('鉴权闸门');
expect('未登录 /me',        'GET',  '/api/me/favorites', 401, 401);
expect('未登录 admin',      'GET',  '/api/admin/overview', 401, 401);
expect('伪造 token',        'GET',  '/api/me/favorites', 401, 401, [], 'abc.def');
expect('篡改签名 token',     'GET',  '/api/admin/overview', 401, 401, [], $adminToken . 'x');

// ---------------------------------------------------------------- 管理端读
section('管理员接口（读）');
ok('auth/me',        'GET', '/api/auth/me', [], $adminToken, fn($d) => (int) ($d['id'] ?? 0) === $uid);
ok('admin/overview', 'GET', '/api/admin/overview', [], $adminToken, fn($d) => isset($d['disk']));
ok('admin/logs',     'GET', '/api/admin/logs', [], $adminToken);
ok('admin/animes',   'GET', '/api/admin/animes?per_page=5', [], $adminToken, fn($d) => isset($d['total']));
ok('admin/rules',    'GET', '/api/admin/rules', [], $adminToken, fn($d) => isset($d['list']));
ok('admin/feeds',    'GET', '/api/admin/feeds', [], $adminToken, fn($d) => isset($d['list']));
ok('admin/items',    'GET', '/api/admin/items?per_page=5', [], $adminToken, fn($d) => isset($d['total']));
ok('admin/items unread', 'GET', '/api/admin/items?unmatched=1&per_page=5', [], $adminToken, fn($d) => isset($d['total']));
ok('admin/settings', 'GET', '/api/admin/settings', [], $adminToken, fn($d) => isset($d['settings']));
ok('admin/users',    'GET', '/api/admin/users?per_page=5', [], $adminToken, fn($d) => isset($d['total']));
ok('admin/users 搜索', 'GET', '/api/admin/users?keyword=' . urlencode((string) $admin['username']), [], $adminToken, fn($d) => isset($d['total']));
ok('admin/invites',  'GET', '/api/admin/invites', [], $adminToken, fn($d) => isset($d['list']));
ok('admin/mails',    'GET', '/api/admin/mails', [], $adminToken, fn($d) => isset($d['todo'], $d['failed']));

// qBittorrent 联通性
section('qBittorrent 联通');
[$c, $r] = req('GET', '/api/admin/torrents', [], $adminToken);
$qbOk = $c === 200 && ($r['code'] ?? -1) === 0;
if ($qbOk) {
    $pass++;
    $cnt = is_array($r['data']['list'] ?? null) ? count($r['data']['list']) : 0;
    printf("  \033[32mPASS\033[0m  qBittorrent WebUI 可访问（当前任务 %d 个）\n", $cnt);
} else {
    $fail++;
    $failures[] = 'admin/torrents';
    printf("  \033[31mFAIL\033[0m  qBittorrent 不可用：HTTP %d code=%s msg=%s\n", $c, (string) ($r['code'] ?? '-'), (string) ($r['msg'] ?? ''));
}

// ---------------------------------------------------------------- 个人读
section('个人接口（读）');
ok('me/favorites', 'GET', '/api/me/favorites', [], $adminToken, fn($d) => isset($d['list']));
ok('me/history',   'GET', '/api/me/history', [], $adminToken, fn($d) => isset($d['list']));
ok('me/continue',  'GET', '/api/me/continue', [], $adminToken, fn($d) => isset($d['list']));
ok('me/stats',     'GET', '/api/me/stats', [], $adminToken, fn($d) => is_array($d));

// ---------------------------------------------------------------- 内容闭环
section('内容闭环：番剧 + 规则 + 可视化筛选');
$animeId = 0;
[$c, $r] = req('POST', '/api/admin/animes', ['title' => $TMP_ANIME, 'year' => 2026, 'season' => 3], $adminToken);
if ($c === 200 && ($r['code'] ?? -1) === 0 && !empty($r['data']['id'])) {
    $animeId = (int) $r['data']['id'];
    $pass++;
    printf("  \033[32mPASS\033[0m  创建番剧 #%d\n", $animeId);
} else {
    $fail++;
    $failures[] = 'POST /api/admin/animes';
    printf("  \033[31mFAIL\033[0m  创建番剧：%s\n", (string) ($r['msg'] ?? ''));
}

if ($animeId > 0) {
    ok('更新番剧', 'PUT', "/api/admin/animes/{$animeId}", ['summary' => '端到端测试', 'status' => 1], $adminToken);
    // 局部更新不得清空未提交的字段（year/season）
    ok('局部更新保留 year', 'GET', "/api/animes/{$animeId}", [], null,
        fn($d) => (int) ($d['anime']['year'] ?? 0) === 2026 && (int) ($d['anime']['season'] ?? 0) === 3);
    ok('番剧详情(公开)', 'GET', "/api/animes/{$animeId}", [], null, fn($d) => (int) ($d['anime']['id'] ?? 0) === $animeId);

    // 规则必须能创建，并触发历史回填
    $ruleId = 0;
    [$c, $r] = req('POST', '/api/admin/rules', [
        'anime_id' => $animeId, 'pattern' => $TMP_ANIME, 'flags' => 'iu', 'ep_capture' => 1,
    ], $adminToken);
    if ($c === 200 && ($r['code'] ?? -1) === 0 && !empty($r['data']['id'])) {
        $ruleId = (int) $r['data']['id'];
        $pass++;
        printf("  \033[32mPASS\033[0m  创建匹配规则 #%d（回填 %d 条）\n", $ruleId, (int) ($r['data']['backfilled'] ?? 0));
    } else {
        $fail++;
        $failures[] = 'POST /api/admin/rules';
        printf("  \033[31mFAIL\033[0m  创建规则：%s\n", (string) ($r['msg'] ?? ''));
    }

    // 非法正则必须被拒绝
    expect('非法正则被拒', 'POST', '/api/admin/rules', 200, 1,
        ['anime_id' => $animeId, 'pattern' => '(unclosed', 'flags' => 'iu'], $adminToken);

    ok('规则列表(按番剧)', 'GET', "/api/admin/rules?anime_id={$animeId}", [], $adminToken,
        fn($d) => count($d['list'] ?? []) >= 1);

    // 可视化筛选台
    ok('规则预览', 'POST', '/api/admin/rules/preview',
        ['pattern' => '\[', 'flags' => 'iu', 'limit' => 50], $adminToken,
        fn($d) => isset($d['hit'], $d['total'], $d['hint']));

    ok('正则草稿生成', 'POST', '/api/admin/rules/suggest',
        ['title' => $TMP_ANIME . ' - 01 [1080p]'], $adminToken,
        fn($d) => !empty($d['pattern']));

    if ($ruleId > 0) {
        ok('更新规则', 'PUT', "/api/admin/rules/{$ruleId}", ['priority' => 99, 'enabled' => 1], $adminToken);
        ok('删除规则', 'DELETE', "/api/admin/rules/{$ruleId}", [], $adminToken);
    }

    ok('删除番剧', 'DELETE', "/api/admin/animes/{$animeId}", [], $adminToken);
    expect('删除后详情 404', 'GET', "/api/animes/{$animeId}", 404, 404);
}

// ---------------------------------------------------------------- 保存规则即加种
section('规则保存后自动加种：真实 qB 往返（全部自清理）');

// 拿一条真实的待处理条目当靶子，测完原样还原。
// 限长是为了让 preg_quote 之后的 pattern 不超 match_rule.pattern 的 500 字符。
$probe = Database::one(
    'SELECT `id` FROM `rss_item`
     WHERE `status` = 0 AND `magnet` LIKE "magnet:%" AND CHAR_LENGTH(`title`) <= 180
     ORDER BY `id` ASC LIMIT 1'
);

if ($probe === null) {
    printf("  \033[33mSKIP\033[0m  条目池里没有带磁力的待处理条目，跳过真实加种往返\n");
} else {
    $probeId = (int) $probe['id'];
    $before  = Database::one(
        'SELECT `status`, `anime_id`, `ep_no`, `matched_rule_id`, `torrent_hash`, `message`, `added_at`
         FROM `rss_item` WHERE `id` = :id',
        ['id' => $probeId]
    );
    $probeTitle = (string) Database::val('SELECT `title` FROM `rss_item` WHERE `id` = :id', ['id' => $probeId]);

    $dlAnime = (int) Database::insert('anime', [
        'title' => $TMP_ANIME . '加种', 'year' => 2026, 'status' => 0,
    ]);

    // 用整条标题做正则，保证只命中这一条
    $titleRe = preg_quote($probeTitle, '#');

    [$code, $res] = req('POST', '/api/admin/rules', [
        'anime_id' => $dlAnime, 'pattern' => $titleRe, 'flags' => 'iu',
        'ep_capture' => 0, 'auto_download' => 1,
    ], $adminToken);

    $ruleDlId = (int) ($res['data']['id'] ?? 0);
    $stats    = $res['data']['download'] ?? null;

    if ($code !== 200 || ($res['code'] ?? -1) !== 0 || $ruleDlId <= 0) {
        $fail++;
        $failures[] = 'POST /api/admin/rules (auto_download)';
        printf("  \033[31mFAIL\033[0m  建规则触发下载：%s\n", (string) ($res['msg'] ?? ''));
    } else {
        // 保存响应必须带上加种统计
        if (is_array($stats) && array_key_exists('added', $stats)) {
            $pass++;
            printf("  \033[32mPASS\033[0m  保存规则返回加种统计（scanned %d / added %d / blocked %d）\n",
                (int) ($stats['scanned'] ?? 0), (int) ($stats['added'] ?? 0), (int) ($stats['blocked'] ?? 0));
        } else {
            $fail++;
            $failures[] = 'rules.download 字段缺失';
            printf("  \033[31mFAIL\033[0m  保存规则未返回 download 统计\n");
        }

        $after = Database::one(
            'SELECT `status`, `torrent_hash`, `anime_id` FROM `rss_item` WHERE `id` = :id',
            ['id' => $probeId]
        );
        $added    = (int) ($stats['added'] ?? 0);
        $blocked  = (int) ($stats['blocked'] ?? 0);
        $hash     = (string) ($after['torrent_hash'] ?? '');

        if ($added === 1 && (int) $after['status'] === 1 && $hash !== '') {
            $pass++;
            printf("  \033[32mPASS\033[0m  条目 #%d 已加种（torrent %s…）\n", $probeId, substr($hash, 0, 12));
        } elseif ($added === 0 && $blocked > 0) {
            printf("  \033[33mSKIP\033[0m  加种被护栏拦下（%s），未验证真实往返\n",
                (string) ($stats['notes'][0] ?? '未知原因'));
        } else {
            $fail++;
            $failures[] = '规则保存后未加种';
            printf("  \033[31mFAIL\033[0m  期望加种 1 条，实得 added=%d status=%s hash=%s\n",
                $added, (string) ($after['status'] ?? '-'), $hash === '' ? '(空)' : '有');
        }

        // 规则列表必须带回 auto_download，前端才好显示开关状态
        [$lc, $lr] = req('GET', '/api/admin/rules?anime_id=' . $dlAnime, [], $adminToken);
        $hasFlag = false;
        foreach (($lr['data']['list'] ?? []) as $row) {
            if (array_key_exists('auto_download', $row)) {
                $hasFlag = true;
                break;
            }
        }
        if ($hasFlag) {
            $pass++;
            printf("  \033[32mPASS\033[0m  规则列表带回 auto_download 字段\n");
        } else {
            $fail++;
            $failures[] = 'rules 列表缺 auto_download';
            printf("  \033[31mFAIL\033[0m  规则列表未返回 auto_download\n");
        }

        // 去 qB 确认任务真的存在
        if ($hash !== '') {
            [$tc, $tr] = req('GET', '/api/admin/torrents', [], $adminToken);
            $found = false;
            foreach (($tr['data']['list'] ?? []) as $t) {
                if (($t['hash'] ?? '') === $hash) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $pass++;
                printf("  \033[32mPASS\033[0m  qBittorrent 中确认到该任务\n");
            } else {
                $fail++;
                $failures[] = 'qB 中找不到刚加的任务';
                printf("  \033[31mFAIL\033[0m  qBittorrent 任务列表中找不到 %s…\n", substr($hash, 0, 12));
            }

            // 手动下载接口：该集已加种，去重护栏应把它挡掉
            [$dc, $dr] = req('POST', "/api/admin/rules/{$ruleDlId}/download", [], $adminToken);
            if ($dc === 200 && ($dr['code'] ?? -1) === 0 && (int) ($dr['data']['added'] ?? -1) === 0) {
                $pass++;
                printf("  \033[32mPASS\033[0m  手动「下载命中」幂等（同集未重复加种）\n");
            } else {
                $fail++;
                $failures[] = 'rules/{id}/download 幂等性';
                printf("  \033[31mFAIL\033[0m  重复下载未去重：added=%s\n", (string) ($dr['data']['added'] ?? '-'));
            }
        }

        // ---- 清理：删种（含文件） → 删规则 → 删番剧 → 还原条目 ----
        if ($hash !== '') {
            req('POST', '/api/admin/torrents/action',
                ['action' => 'delete', 'hashes' => [$hash], 'delete_files' => true], $adminToken);
        }
        req('DELETE', "/api/admin/rules/{$ruleDlId}", [], $adminToken);
        req('DELETE', "/api/admin/animes/{$dlAnime}", [], $adminToken);
        if ($before !== null) {
            Database::update('rss_item', $before, '`id` = :id', ['id' => $probeId]);
        }

        printf("  \033[36m..\033[0m    已清理：qB 任务、规则 #%d、番剧 #%d、条目 #%d 已还原\n",
            $ruleDlId, $dlAnime, $probeId);
    }
}

// 关掉自动下载时不应触碰 qB
$dlOffAnime = (int) Database::insert('anime', ['title' => $TMP_ANIME . '关闭', 'year' => 2026, 'status' => 0]);
[$c, $r] = req('POST', '/api/admin/rules', [
    'anime_id' => $dlOffAnime, 'pattern' => preg_quote($TMP_ANIME . '关闭', '#'),
    'flags' => 'iu', 'ep_capture' => 0, 'auto_download' => 0,
], $adminToken);
if ($c === 200 && ($r['code'] ?? -1) === 0 && ($r['data']['download'] ?? null) === null
    && (int) ($r['data']['auto_download'] ?? -1) === 0) {
    $pass++;
    printf("  \033[32mPASS\033[0m  关闭自动下载时不触发加种\n");
} else {
    $fail++;
    $failures[] = 'auto_download=0 仍触发下载';
    printf("  \033[31mFAIL\033[0m  auto_download=0 行为异常：%s\n", json_encode($r['data'] ?? null, JSON_UNESCAPED_UNICODE));
}
$offRuleId = (int) ($r['data']['id'] ?? 0);
if ($offRuleId > 0) {
    req('DELETE', "/api/admin/rules/{$offRuleId}", [], $adminToken);
}
req('DELETE', "/api/admin/animes/{$dlOffAnime}", [], $adminToken);

// 不存在的规则应 404
expect('不存在的规则下载 404', 'POST', '/api/admin/rules/99999999/download', 404, 404, [], $adminToken);

// ---------------------------------------------------------------- 播放链路
section('播放链路：剧集详情 + X-Accel 流媒体 + 目录穿越防护');

/** 拿完整响应头（流媒体要看 206 / X-Accel） */
function raw(string $path, array $headers = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $got = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        // 同 req()：跟随 HTTP→HTTPS 跳转，跳过 IP 访问的证书校验
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$got) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $got[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $got, $body];
}

$root   = rtrim((string) Database::val('SELECT `v` FROM `setting` WHERE `k` = "download_root"'), '/');
$root   = $root !== '' ? $root : '/data/downloads/anime';
$e2eDir = $root . '/__e2e_' . $STAMP;
@mkdir($e2eDir, 0775, true);

$playAnime = (int) Database::insert('anime', ['title' => $TMP_ANIME . '播放', 'year' => 2026, 'status' => 1]);
$playEps   = [];
foreach ([1, 2] as $n) {
    $f = $e2eDir . '/ep' . sprintf('%02d', $n) . '.mp4';
    // 造一份非全零数据，便于观察 Range 偏移是否正确
    file_put_contents($f, str_repeat(chr(65 + $n) . 'anime-new-range-' . $STAMP . "\n", 4000));
    $playEps[$n] = (int) Database::insert('episode', [
        'anime_id'  => $playAnime,
        'ep_no'     => $n,
        'title'     => 'ep' . sprintf('%02d', $n) . '.mp4',
        'file_path' => $f,
        'file_size' => (int) filesize($f),
        'subgroup'  => 'E2E',
        'resolution' => '1080p',
    ]);
}
$ep1 = $playEps[1];
$ep2 = $playEps[2];
$playToken = Auth::issue($uid, 'admin');

ok('剧集详情', 'GET', "/api/episodes/{$ep1}", [], $playToken,
    fn($d) => (int) ($d['episode']['id'] ?? 0) === $ep1
        && (int) ($d['next']['id'] ?? 0) === $ep2
        && count($d['siblings'] ?? []) === 2);

ok('选集 siblings', 'GET', "/api/episodes/{$ep2}", [], $playToken,
    fn($d) => (int) ($d['prev']['id'] ?? 0) === $ep1 && $d['next'] === null);

// 观看进度上报 → 收藏/继续观看链路
ok('上报进度', 'POST', '/api/me/progress',
    ['episode_id' => $ep1, 'position_sec' => 30, 'duration_sec' => 1000], $playToken);
ok('我的历史', 'GET', '/api/me/history', [], $playToken,
    fn($d) => count(array_filter($d['list'] ?? [], fn($x) => (int) $x['episode_id'] === $ep1)) === 1);
ok('继续观看', 'GET', '/api/me/continue', [], $playToken,
    fn($d) => count(array_filter($d['list'] ?? [], fn($x) => (int) $x['episode_id'] === $ep1)) === 1);

ok('加入追番', 'POST', "/api/me/favorites/{$playAnime}", [], $playToken, fn($d) => ($d['is_fav'] ?? false) === true);
ok('追番列表(含观看集数)', 'GET', '/api/me/favorites', [], $playToken,
    fn($d) => count(array_filter($d['list'] ?? [], fn($x) => (int) $x['id'] === $playAnime
        && (float) $x['watched_ep'] === 1.0)) === 1);
ok('首页(登录态 fav_updates)', 'GET', '/api/home', [], $playToken,
    fn($d) => count(array_filter($d['fav_updates'] ?? [], fn($x) => (int) $x['id'] === $playAnime)) === 1);
ok('取消追番', 'DELETE', "/api/me/favorites/{$playAnime}", [], $playToken, fn($d) => ($d['is_fav'] ?? true) === false);

// 流媒体：无令牌 401
[$c, $h, $b] = raw("/stream/{$ep1}");
if ($c === 401) {
    $pass++;
    echo "  \033[32mPASS\033[0m  流媒体无令牌 → 401\n";
} else {
    $fail++;
    $failures[] = 'stream no-token';
    printf("  \033[31mFAIL\033[0m  流媒体无令牌返回 %d\n", $c);
}

// 流媒体：带令牌整段拉取
[$c, $h, $b] = raw("/stream/{$ep1}?token=" . urlencode($playToken));
$size = (int) filesize($e2eDir . '/ep01.mp4');
if ($c === 200 && strlen($b) === $size && ($h['accept-ranges'] ?? '') === 'bytes') {
    $pass++;
    printf("  \033[32mPASS\033[0m  流媒体整段 %d 字节，Accept-Ranges: bytes\n", $size);
} else {
    $fail++;
    $failures[] = 'stream full';
    printf("  \033[31mFAIL\033[0m  流媒体整段：HTTP %d len=%d（期望 %d）accept-ranges=%s\n",
        $c, strlen($b), $size, (string) ($h['accept-ranges'] ?? '-'));
}

// 流媒体：Range 请求必须 206 且内容正确
[$c, $h, $b] = raw("/stream/{$ep1}?token=" . urlencode($playToken), ['Range: bytes=10-29']);
$want = substr((string) file_get_contents($e2eDir . '/ep01.mp4'), 10, 20);
if ($c === 206 && $b === $want && ($h['content-range'] ?? '') === "bytes 10-29/{$size}") {
    $pass++;
    echo "  \033[32mPASS\033[0m  Range 206，Content-Range 正确，字节内容一致\n";
} else {
    $fail++;
    $failures[] = 'stream range';
    printf("  \033[31mFAIL\033[0m  Range：HTTP %d content-range=%s 内容%s\n",
        $c, (string) ($h['content-range'] ?? '-'), $b === $want ? '一致' : '不一致');
}

// 流媒体：下载模式加 attachment
[$c, $h, $b] = raw("/stream/{$ep1}?dl=1&token=" . urlencode($playToken));
if ($c === 200 && str_contains((string) ($h['content-disposition'] ?? ''), 'attachment')) {
    $pass++;
    echo "  \033[32mPASS\033[0m  dl=1 返回 attachment（可给外部播放器）\n";
} else {
    $fail++;
    $failures[] = 'stream dl';
    printf("  \033[31mFAIL\033[0m  dl=1：HTTP %d disposition=%s\n", $c, (string) ($h['content-disposition'] ?? '-'));
}

// 目录穿越：指向下载根目录之外的文件必须被拒
$evil = (int) Database::insert('episode', [
    'anime_id' => $playAnime, 'ep_no' => 99,
    'title' => 'evil.mp4', 'file_path' => '/etc/hostname',
    'file_size' => 1, 'subgroup' => 'E2E',
]);
[$c, $h, $b] = raw("/stream/{$evil}?token=" . urlencode($playToken));
if ($c === 403) {
    $pass++;
    echo "  \033[32mPASS\033[0m  目录穿越被拒（403）\n";
} else {
    $fail++;
    $failures[] = 'stream traversal';
    printf("  \033[31mFAIL\033[0m  目录穿越未拦截，返回 %d\n", $c);
}

// 清理播放测试数据
Database::run('DELETE FROM `episode` WHERE `anime_id` = :a', ['a' => $playAnime]);
Database::run('DELETE FROM `user_history` WHERE `anime_id` = :a', ['a' => $playAnime]);
Database::run('DELETE FROM `user_favorite` WHERE `anime_id` = :a', ['a' => $playAnime]);
Database::run('DELETE FROM `anime` WHERE `id` = :a', ['a' => $playAnime]);
array_map('unlink', glob($e2eDir . '/*') ?: []);
@rmdir($e2eDir);
echo "  （播放测试数据已清理）\n";

// ---------------------------------------------------------------- 字幕链路
section('字幕链路：服务端抽取 → 落盘缓存 → 鉴权分发');

// 造一个自带 SRT 字幕轨的 mkv（用 ffmpeg 现场生成，不依赖外部素材）
$subDir = $e2eDir . '/sub';
@mkdir($subDir, 0775, true);
$srt1 = $subDir . '/zh.srt';
$srt2 = $subDir . '/en.srt';
file_put_contents($srt1, "1\n00:00:01,000 --> 00:00:03,000\n简体中文字幕測試\n\n2\n00:00:04,000 --> 00:00:06,000\n第二條\n");
file_put_contents($srt2, "1\n00:00:01,000 --> 00:00:03,000\nEnglish subtitle test\n\n2\n00:00:04,000 --> 00:00:06,000\nSecond cue\n");

$mkv = $subDir . '/sub-test.mkv';
$ffmpeg = SubtitleService::ffmpegBin();
$cmd = sprintf(
    'timeout 90 %s -nostdin -v error -f lavfi -i color=c=black:s=320x240:d=8 -f lavfi -i anullsrc=r=44100:cl=mono '
    . '-i %s -i %s -map 0:v -map 1:a -map 2 -map 3 -c:v libx264 -preset ultrafast -pix_fmt yuv420p '
    . '-c:a aac -c:s srt -t 8 -shortest -metadata:s:s:0 language=chi -metadata:s:s:0 title=简体中文 '
    . '-metadata:s:s:1 language=eng -metadata:s:s:1 title=English %s -y 2>&1',
    escapeshellcmd($ffmpeg),
    escapeshellarg($srt1),
    escapeshellarg($srt2),
    escapeshellarg($mkv)
);
$mkvOut = [];
$mkvRc = 0;
@exec($cmd, $mkvOut, $mkvRc);

$subEp = 0;
if ($mkvRc === 0 && is_file($mkv) && filesize($mkv) > 0) {
    $subAnime = (int) Database::insert('anime', ['title' => $TMP_ANIME . '字幕', 'year' => 2026, 'status' => 1]);
    $subEp = (int) Database::insert('episode', [
        'anime_id'   => $subAnime,
        'ep_no'      => 1,
        'title'      => 'sub-test.mkv',
        'file_path'  => $mkv,
        'file_size'  => (int) filesize($mkv),
        'subgroup'   => 'E2E',
        'resolution' => '240p',
    ]);

    // 1) 抽取
    $r = SubtitleService::extract($subEp, true);
    if ($r['ok'] && count($r['tracks']) === 2) {
        $pass++;
        $labels = implode('、', array_map(static fn($t) => $t['label'], $r['tracks']));
        echo "  \033[32mPASS\033[0m  服务端抽取 2 条字幕轨（{$labels}）\n";
    } else {
        $fail++;
        $failures[] = 'subtitle extract';
        printf("  \033[31mFAIL\033[0m  抽取失败 reason=%s error=%s\n", (string) $r['reason'], (string) $r['error']);
    }

    // 2) WebVTT 头与内容
    $t0 = $r['tracks'][0] ?? null;
    $vtt = $t0 ? SubtitleService::vttPath($subEp, (int) $t0['track_index']) : null;
    $head = $vtt ? (string) file_get_contents($vtt) : '';
    if ($vtt !== null && str_starts_with($head, 'WEBVTT') && str_contains($head, '简体中文字幕測試')) {
        $pass++;
        printf("  \033[32mPASS\033[0m  WebVTT 落盘正确（%d 条 cue，%d 字节）\n", (int) $t0['cues'], (int) $t0['bytes']);
    } else {
        $fail++;
        $failures[] = 'subtitle vtt file';
        echo "  \033[31mFAIL\033[0m  WebVTT 落盘内容不正确\n";
    }

    // 3) 幂等：再抽一次必须命中缓存且不重复写库
    $before = (int) Database::val('SELECT COUNT(*) FROM `episode_subtitle` WHERE `episode_id` = :e', ['e' => $subEp], 0);
    $again  = SubtitleService::extract($subEp);
    $after  = (int) Database::val('SELECT COUNT(*) FROM `episode_subtitle` WHERE `episode_id` = :e', ['e' => $subEp], 0);
    if ($again['skipped'] && $again['reason'] === 'cached' && $before === $after) {
        $pass++;
        echo "  \033[32mPASS\033[0m  重复抽取命中缓存（轨数仍为 {$after}）\n";
    } else {
        $fail++;
        $failures[] = 'subtitle idempotent';
        printf("  \033[31mFAIL\033[0m  幂等失败 reason=%s before=%d after=%d\n", (string) $again['reason'], $before, $after);
    }

    // 4) 播放接口带出字幕清单
    ok('剧集详情含字幕', 'GET', "/api/episodes/{$subEp}", [], $adminToken,
        fn($d) => count($d['episode']['subtitles'] ?? []) === 2
            && ($d['episode']['subtitle_state']['extracted_at'] ?? null) !== null);

    // 5) 字幕清单接口
    ok('GET /api/episodes/{id}/subtitles', 'GET', "/api/episodes/{$subEp}/subtitles", [], $adminToken,
        fn($d) => count($d['tracks'] ?? []) === 2 && (int) ($d['embedded'] ?? 0) === 2);

    // 6) VTT 分发：状态码 / MIME / 内容 / ETag
    [$vc, $vh, $vb] = raw("/subtitle/{$subEp}/0.vtt?token=" . urlencode($adminToken));
    if ($vc === 200
        && str_contains(strtolower($vh['content-type'] ?? ''), 'text/vtt')
        && str_starts_with($vb, 'WEBVTT')
        && isset($vh['etag'])) {
        $pass++;
        printf("  \033[32mPASS\033[0m  VTT 分发 %d 字节 · %s\n", strlen($vb), $vh['content-type'] ?? '');
    } else {
        $fail++;
        $failures[] = 'subtitle serve';
        printf("  \033[31mFAIL\033[0m  VTT 分发异常 HTTP %d type=%s\n", $vc, $vh['content-type'] ?? '-');
    }

    // 7) 条件请求命中 304
    if (isset($vh['etag'])) {
        [$c304] = raw("/subtitle/{$subEp}/0.vtt?token=" . urlencode($adminToken), ['If-None-Match: ' . $vh['etag']]);
        if ($c304 === 304) {
            $pass++;
            echo "  \033[32mPASS\033[0m  字幕条件请求命中 304\n";
        } else {
            $fail++;
            $failures[] = 'subtitle 304';
            printf("  \033[31mFAIL\033[0m  条件请求返回 %d\n", $c304);
        }
    }

    // 8) 下载模式带 Content-Disposition
    [$dc, $dh] = raw("/subtitle/{$subEp}/0.vtt?token=" . urlencode($adminToken) . '&dl=1');
    if ($dc === 200 && str_contains(strtolower($dh['content-disposition'] ?? ''), 'attachment')) {
        $pass++;
        echo "  \033[32mPASS\033[0m  字幕可下载（Content-Disposition: attachment）\n";
    } else {
        $fail++;
        $failures[] = 'subtitle download';
        printf("  \033[31mFAIL\033[0m  下载模式异常 HTTP %d\n", $dc);
    }

    // 9) 鉴权边界（字幕分发返回纯文本，只校验 HTTP 状态）
    expectHttp('字幕无令牌', 'GET', "/subtitle/{$subEp}/0.vtt", 401);
    expectHttp('字幕错令牌', 'GET', "/subtitle/{$subEp}/0.vtt?token=bad", 401);
    expectHttp('不存在的字幕轨', 'GET', "/subtitle/{$subEp}/9.vtt?token=" . urlencode($adminToken), 404);
    expectHttp('匿名触发抽取', 'POST', "/api/episodes/{$subEp}/subtitles/extract", 401);

    // 10) 路径穿越防护
    //     file 字段一律先 basename 再 realpath，且必须落在缓存根目录内。
    //     真正有杀伤力的是「缓存目录里藏一个指向外部的软链」——这里就照这个来测。
    $secret = $e2eDir . '/secret.vtt';
    file_put_contents($secret, "WEBVTT\n\n00:00.000 --> 00:01.000\n不该被读到的内容\n");
    $cacheDir = SubtitleService::root() . '/' . $subEp;
    @mkdir($cacheDir, 0775, true);
    @unlink($cacheDir . '/evil.vtt');
    @symlink($secret, $cacheDir . '/evil.vtt');

    Database::insert('episode_subtitle', [
        'episode_id'   => $subEp,
        'track_index'  => 7,
        'stream_index' => 7,
        'codec'        => 'subrip',
        'language'     => 'zh',
        'label'        => 'evil',
        'file'         => 'evil.vtt',
        'cues'         => 1,
        'bytes'        => 1,
        'source_mtime' => 0,
    ]);
    $evilPath = SubtitleService::vttPath($subEp, 7);
    Database::run('DELETE FROM `episode_subtitle` WHERE `episode_id` = :e AND `track_index` = 7', ['e' => $subEp]);
    @unlink($cacheDir . '/evil.vtt');
    @unlink($secret);

    if ($evilPath === null) {
        $pass++;
        echo "  \033[32mPASS\033[0m  软链穿透被拒（缓存目录内符号链接指向外部 → null）\n";
    } else {
        $fail++;
        $failures[] = 'subtitle traversal';
        echo "  \033[31mFAIL\033[0m  穿透未被拦截：{$evilPath}\n";
    }

    // 11) 源文件被删除后应记为失败并可被复检
    @unlink($mkv);
    $gone = SubtitleService::extract($subEp, true);
    if (!$gone['ok'] && $gone['reason'] === 'file_missing') {
        $pass++;
        echo "  \033[32mPASS\033[0m  源文件消失时给出明确原因（file_missing）\n";
    } else {
        $fail++;
        $failures[] = 'subtitle missing file';
        printf("  \033[31mFAIL\033[0m  文件消失未正确报告 reason=%s\n", (string) $gone['reason']);
    }

    // 12) 清理：级联删除应带走字幕记录
    SubtitleService::purge($subEp);
    Database::run('DELETE FROM `episode` WHERE `anime_id` = :a', ['a' => $subAnime]);
    $left = (int) Database::val('SELECT COUNT(*) FROM `episode_subtitle` WHERE `episode_id` = :e', ['e' => $subEp], 0);
    Database::run('DELETE FROM `anime` WHERE `id` = :a', ['a' => $subAnime]);
    if ($left === 0) {
        $pass++;
        echo "  \033[32mPASS\033[0m  字幕记录随剧集清理干净\n";
    } else {
        $fail++;
        $failures[] = 'subtitle cleanup';
        printf("  \033[31mFAIL\033[0m  仍有 %d 条字幕记录残留\n", $left);
    }
} else {
    echo "  \033[33mSKIP\033[0m  ffmpeg 无法生成测试用 mkv，跳过字幕链路\n";
}
@array_map('unlink', glob($subDir . '/*') ?: []);
@rmdir($subDir);

// ---------------------------------------------------------------- 设置闭环
section('设置闭环');
[$c, $r] = req('GET', '/api/admin/settings', [], $adminToken);
$curName = (string) ($r['data']['settings']['site_name'] ?? '');
$curUrl  = (string) ($r['data']['settings']['site_url'] ?? '');

ok('保存设置(原值回写)', 'PUT', '/api/admin/settings',
    ['site_name' => $curName, 'site_url' => $curUrl], $adminToken,
    fn($d) => is_array($d['saved'] ?? null));

// 密钥掩码语义：回传 __SAVED__ 不应覆盖真实值
$qbBefore = (string) Database::val('SELECT `v` FROM `setting` WHERE `k` = "qb_pass"');
[$c, $r] = req('PUT', '/api/admin/settings', ['qb_pass' => '__SAVED__'], $adminToken);
$qbAfter = (string) Database::val('SELECT `v` FROM `setting` WHERE `k` = "qb_pass"');
if ($qbBefore === $qbAfter && $qbBefore !== '') {
    $pass++;
    echo "  \033[32mPASS\033[0m  密钥掩码未覆盖真实值\n";
} else {
    $fail++;
    $failures[] = 'qb_pass mask';
    echo "  \033[31mFAIL\033[0m  密钥被掩码覆盖了\n";
}

// ---------------------------------------------------------------- 邀请码闭环
section('邀请码闭环');
[$c, $r] = req('POST', '/api/admin/invites/batch',
    ['count' => 3, 'max_uses' => 2, 'expire_days' => 7, 'note' => 'e2e ' . $STAMP], $adminToken);
$codes = $r['data']['codes'] ?? [];
if ($c === 200 && count($codes) === 3) {
    $pass++;
    printf("  \033[32mPASS\033[0m  批量生成邀请码 %d 个\n", count($codes));
} else {
    $fail++;
    $failures[] = 'invites/batch';
    printf("  \033[31mFAIL\033[0m  生成邀请码：%s\n", (string) ($r['msg'] ?? ''));
}

$invIds = array_column(Database::all(
    'SELECT `id` FROM `invite_code` WHERE `note` = :n', ['n' => 'e2e ' . $STAMP]
), 'id');
if ($invIds) {
    ok('邀请码启用作废切换', 'POST', '/api/admin/invites/' . (int) $invIds[0] . '/toggle', [], $adminToken);
    ok('邀请码列表可见', 'GET', '/api/admin/invites', [], $adminToken,
        fn($d) => count(array_filter($d['list'] ?? [], fn($x) => str_contains((string) $x['note'], $STAMP))) >= 1);
    foreach ($invIds as $iid) {
        req('DELETE', '/api/admin/invites/' . (int) $iid, [], $adminToken);
    }
    $left = (int) Database::val('SELECT COUNT(*) FROM `invite_code` WHERE `note` = :n', ['n' => 'e2e ' . $STAMP]);
    if ($left === 0) {
        $pass++;
        echo "  \033[32mPASS\033[0m  邀请码已清理\n";
    } else {
        $fail++;
        $failures[] = 'invite cleanup';
        echo "  \033[31mFAIL\033[0m  邀请码残留 $left 个\n";
    }
}

// ---------------------------------------------------------------- 用户闭环
section('用户闭环 + 越权拒绝');
$tmpUserId = 0;
[$c, $r] = req('POST', '/api/admin/users',
    ['username' => $TMP_USER, 'email' => $TMP_MAIL, 'password' => 'E2eTest12345', 'role' => 'user'], $adminToken);
if ($c === 200 && !empty($r['data']['id'])) {
    $tmpUserId = (int) $r['data']['id'];
    $pass++;
    printf("  \033[32mPASS\033[0m  管理员开号 #%d\n", $tmpUserId);
} else {
    $fail++;
    $failures[] = 'POST /api/admin/users';
    printf("  \033[31mFAIL\033[0m  开号失败：%s\n", (string) ($r['msg'] ?? ''));
}

// 弱密码必须被拒
expect('弱密码被拒', 'POST', '/api/admin/users', 200, 1,
    ['username' => 'weak' . $STAMP, 'email' => "w{$STAMP}@example.invalid", 'password' => '123'], $adminToken);

// 重复用户名必须被拒
expect('重名被拒', 'POST', '/api/admin/users', 200, 1,
    ['username' => $TMP_USER, 'email' => "d{$STAMP}@example.invalid", 'password' => 'E2eTest12345'], $adminToken);

if ($tmpUserId > 0) {
    // 拿普通用户令牌，验证管理端越权被拒
    $userToken = Auth::issue($tmpUserId, 'user');
    expect('普通用户读 admin',  'GET', '/api/admin/overview', 403, 403, [], $userToken);
    expect('普通用户写 admin',  'PUT', '/api/admin/settings', 403, 403, ['site_name' => 'x'], $userToken);
    expect('普通用户读 users',  'GET', '/api/admin/users', 403, 403, [], $userToken);

    ok('普通用户可读 /me', 'GET', '/api/me/stats', [], $userToken, fn($d) => is_array($d));

    // 状态机
    ok('置为禁用', 'POST', "/api/admin/users/{$tmpUserId}/status", ['status' => 9], $adminToken);
    // 禁用后原令牌应立即失效（服务端每次回查）
    expect('禁用后令牌失效', 'GET', '/api/me/stats', 401, 401, [], $userToken);
    ok('放行', 'POST', "/api/admin/users/{$tmpUserId}/status", ['status' => 1], $adminToken);
    ok('升为管理员', 'POST', "/api/admin/users/{$tmpUserId}/role", ['role' => 'admin'], $adminToken);
    ok('降回普通用户', 'POST', "/api/admin/users/{$tmpUserId}/role", ['role' => 'user'], $adminToken);

    // 不能修改自己
    expect('不能禁自己', 'POST', "/api/admin/users/{$uid}/status", 200, 1, ['status' => 9], $adminToken);
    expect('不能降自己权', 'POST', "/api/admin/users/{$uid}/role", 200, 1, ['role' => 'user'], $adminToken);

    ok('删除测试用户', 'DELETE', "/api/admin/users/{$tmpUserId}", [], $adminToken);
    $gone = Database::val('SELECT 1 FROM `user` WHERE `id` = :id', ['id' => $tmpUserId]);
    if ($gone === null) {
        $pass++;
        echo "  \033[32mPASS\033[0m  测试用户已清理\n";
    } else {
        $fail++;
        $failures[] = 'user cleanup';
        echo "  \033[31mFAIL\033[0m  测试用户未清理\n";
    }
}

// ---------------------------------------------------------------- 邮件模板
section('邮件（仅入队，不外发）');
$uidBefore = (int) Database::val('SELECT COUNT(*) FROM `mail_queue`');
[$c, $r] = req('POST', '/api/auth/forgot-password', ['email' => $TMP_MAIL]);
$uidAfter = (int) Database::val('SELECT COUNT(*) FROM `mail_queue`');
if ($c === 200 && ($r['code'] ?? -1) === 0) {
    $pass++;
    printf("  \033[32mPASS\033[0m  找回密码已受理（队列 %d → %d）\n", $uidBefore, $uidAfter);
} else {
    $fail++;
    $failures[] = 'forgot-password';
    printf("  \033[31mFAIL\033[0m  找回密码：%s\n", (string) ($r['msg'] ?? ''));
}

// 不存在的邮箱不应泄露账号是否存在
expect('未知邮箱不报错', 'POST', '/api/auth/forgot-password', 200, 0, ['email' => 'nobody@example.invalid']);

// ---------------------------------------------------------------- 注册闸门
section('注册策略闸门');
$cfg = Database::one('SELECT `v` FROM `setting` WHERE `k` = "register_invite"');
$needInvite = $cfg !== null && (string) $cfg['v'] === '1';
if ($needInvite) {
    expect('缺邀请码注册被拒', 'POST', '/api/auth/register', 200, 1,
        ['username' => 'r' . $STAMP, 'email' => "r{$STAMP}@example.invalid", 'password' => 'E2eTest12345']);
    expect('错邀请码注册被拒', 'POST', '/api/auth/register', 200, 1,
        ['username' => 'r' . $STAMP, 'email' => "r{$STAMP}@example.invalid", 'password' => 'E2eTest12345', 'invite_code' => 'ZZZZ-NOPE']);
    echo "  （register_invite=1，邀请码闸门已生效）\n";
} else {
    echo "  （register_invite=0，跳过邀请码闸门）\n";
}

// 清理本次可能产生的邮件
Database::run('DELETE FROM `mail_queue` WHERE `to_email` = :e', ['e' => $TMP_MAIL]);

// =====================================================================
$total = $pass + $fail;
echo "\n" . str_repeat('=', 58) . "\n";
printf("  端到端结果：%d/%d 通过", $pass, $total);
echo $fail === 0 ? "  \033[32m全部通过\033[0m\n" : "  \033[31m{$fail} 项失败\033[0m\n";
if ($failures) {
    echo "  失败清单：\n";
    foreach ($failures as $f) {
        echo "    - $f\n";
    }
}
echo str_repeat('=', 58) . "\n";

exit($fail === 0 ? 0 : 1);
