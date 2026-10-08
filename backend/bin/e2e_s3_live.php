<?php
declare(strict_types=1);

/**
 * 对象存储「真实环境」往返自检 —— 用**后台里现有的配置**打真实的对象存储。
 *
 *   php bin/e2e_s3_live.php [appBaseUrl] [--size=MB]
 *
 * `--size` 指定测试文件大小（默认约 0.5MB）。给到 40 就会跨过 8MB 分片阈值，
 * 从而把分片上传、进度回调、任务日志的进度字段都走一遍。
 *
 * 与 e2e_s3.php 的分工：
 *   e2e_s3.php       用 mock S3 跑 65 项，覆盖签名/分片/失败分支等细节，不改动真实配置
 *   e2e_s3_live.php  用真实配置只跑一条主链路，回答「现在到底能不能传、能不能播」
 *
 * 特点：**不改动任何配置**（只读当前设置），全部测试数据自清理
 * （远端对象 + 剧集记录 + 本地临时文件），跑完不留痕迹。
 *
 * 链路：建集 → 上传 → 校验通过删本地 → /stream 302 → 跟随直链取回字节比对哈希
 *       → Range 206 → dl=1 预签名可取回 → 检查任务日志是否记录了这次上传
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\S3;
use App\Core\Setting;
use App\Service\RemuxService;
use App\Service\S3Uploader;

$BASE = rtrim((string) ($argv[1] ?? 'http://127.0.0.1'), '/');
if (str_starts_with($BASE, '--')) {
    $BASE = 'http://127.0.0.1';
}

// --size=MB：给大一点就能把分片上传链路也走上（默认约 0.5MB，单次 PUT）
$sizeMB = 0.5;
foreach ($argv as $a) {
    if (preg_match('/^--size=([0-9.]+)$/', (string) $a, $m)) {
        $sizeMB = max(0.01, (float) $m[1]);
    }
}

$pass = 0;
$fail = 0;

function section(string $t): void
{
    echo "\n── {$t} ──\n";
}

function check(string $label, bool $okv, string $extra = ''): void
{
    global $pass, $fail;
    if ($okv) {
        $pass++;
        echo "  PASS  {$label}" . ($extra !== '' ? "  {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($extra !== '' ? "  {$extra}" : '') . "\n";
    }
}

function info(string $s): void
{
    echo "        {$s}\n";
}

function http(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $got = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int) ($opt['timeout'] ?? 180),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$got): int {
            $p = strpos($line, ':');
            if ($p !== false) {
                $got[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if (isset($opt['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $got, $body];
}

// ------------------------------------------------------------------ 前置

echo "对象存储真实环境往返自检\n";
echo "  应用 : {$BASE}\n";
echo '  endpoint : ' . (string) Setting::get('s3_endpoint') . "\n";
echo '  bucket   : ' . (string) Setting::get('s3_bucket') . "\n";
echo '  公开基址 : ' . (S3::publicBase() ?: '(未配置)') . "\n";

if (!S3::enabled()) {
    exit("\n对象存储未启用或配置不完整：" . S3::configHint() . "\n");
}
$s3 = S3::fromSettings();

$admin = Database::one('SELECT * FROM `user` WHERE `role` = "admin" AND `status` = 1 ORDER BY `id` LIMIT 1');
if ($admin === null) {
    exit("没有可用的管理员账号\n");
}
$token = Auth::issue((int) $admin['id'], 'admin');

$root = RemuxService::downloadRoot();
$dir  = $root . '/__e2e_live_' . date('mdHis');
if (!@mkdir($dir, 0775, true)) {
    exit("无法创建测试目录 {$dir}\n");
}

$animeId = 0;
$epId    = 0;
$s3Key   = '';

register_shutdown_function(static function () use (&$animeId, &$s3Key, &$dir): void {
    try {
        if ($s3Key !== '' && S3::enabled()) {
            S3::fromSettings()?->delete($s3Key);
        }
    } catch (\Throwable) {
    }
    try {
        if ($animeId > 0) {
            Database::run('DELETE FROM `episode` WHERE `anime_id` = :a', ['a' => $animeId]);
            Database::run('DELETE FROM `anime` WHERE `id` = :a', ['a' => $animeId]);
        }
    } catch (\Throwable) {
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

// ------------------------------------------------------------------ 造数据

section('1. 准备测试数据');

$size = (int) round($sizeMB * 1024 * 1024) + 4321;
$file = $dir . '/live-probe.mp4';
$h    = fopen($file, 'wb');
$chunk = '';
for ($i = 0; $i < 4096; $i++) {
    $chunk .= chr(($i * 11 + 7) % 251);
}
$written = 0;
while ($written < $size) {
    $n = min(strlen($chunk), $size - $written);
    fwrite($h, substr($chunk, 0, $n));
    $written += $n;
}
fclose($h);
$sha = hash_file('sha256', $file);

$animeId = (int) Database::insert('anime', [
    'title'  => '__LIVE_S3自检' . date('mdHis') . '__',
    'year'   => 2026,
    'status' => 1,
]);
$epId = (int) Database::insert('episode', [
    'anime_id'   => $animeId,
    'ep_no'      => 1,
    'title'      => 'live-probe.mp4',
    'file_path'  => $file,
    'file_size'  => $size,
    'vcodec'     => 'h264',
    'acodec'     => 'aac',
    'probe_at'   => date('Y-m-d H:i:s'),
    'sub_tracks' => 0,   // 无内嵌字幕，不阻塞上传队列
]);
check('测试剧集已创建', $epId > 0, 'episode #' . $epId);
info('本地文件 ' . round($size / 1024, 1) . 'KB，sha256 ' . substr($sha, 0, 16) . '…');

// ------------------------------------------------------------------ 上传

section('2. 上传到对象存储');

$up = new S3Uploader();
$t0 = microtime(true);
$r  = $up->upload($epId, true, false, 'manual', 'e2e-live');
$cost = round(microtime(true) - $t0, 1);

check('上传成功', $r['ok'], (string) ($r['error'] ?? ''));
if ($r['key']) {
    $s3Key = (string) $r['key'];
}
info('key: ' . ($s3Key ?: '(空)') . '  耗时 ' . $cost . 's');

$row = Database::one('SELECT * FROM `episode` WHERE `id` = :i', ['i' => $epId]);
check('s3_status 落库为 1', (int) ($row['s3_status'] ?? 0) === 1);
check('远端大小与本地一致', (int) ($row['s3_size'] ?? 0) === $size, (string) ($row['s3_size'] ?? '-'));
check('本地文件已删除（省空间）', !is_file($file));
check('s3_purged_at 已记录', ($row['s3_purged_at'] ?? null) !== null);

// 远端独立取证：HEAD 一次
try {
    $head = $s3->head($s3Key);
    check('远端 HEAD 回查大小一致', $head !== null && $head['size'] === $size, (string) ($head['size'] ?? '缺失'));
} catch (\Throwable $e) {
    check('远端 HEAD 回查大小一致', false, $e->getMessage());
}

// ------------------------------------------------------------------ 任务日志

section('2b. 任务日志（后台「上传任务」页的数据来源）');

$task = Database::one(
    'SELECT * FROM `s3_task` WHERE `episode_id` = :e AND `action` = \'upload\' ORDER BY `id` DESC LIMIT 1',
    ['e' => $epId]
);
check('已记录本次上传任务', $task !== null && (int) $task['status'] === 1, $task ? ('status=' . $task['status']) : '无记录');
if ($task !== null) {
    check('日志字节数与文件一致', (int) $task['bytes_total'] === $size, (string) $task['bytes_total']);
    check('日志写入进度（bytes_done 到终值）', (int) $task['bytes_done'] === $size, (string) $task['bytes_done']);
    check('日志标记已删除本地', (int) $task['purged'] === 1);
    check('日志记录了耗时', (int) $task['duration_ms'] > 0, $task['duration_ms'] . ' ms');
    check('日志带番剧名快照（剧集删除后仍可读）', (string) ($task['anime_title'] ?? '') !== '', (string) ($task['anime_title'] ?? ''));
    check('日志触发方式为 manual', (string) $task['trigger'] === 'manual', (string) $task['trigger']);

    $expectMp = $size > 8 * 1024 * 1024;
    check(
        $expectMp ? '跨过阈值 → 记录为分片上传' : '未跨阈值 → 记录为单次上传',
        (int) $task['multipart'] === ($expectMp ? 1 : 0),
        'multipart=' . $task['multipart'] . ' parts=' . $task['parts_total']
    );
    if ($expectMp) {
        check('分片数 > 1 且已全部完成', (int) $task['parts_total'] > 1 && (int) $task['parts_done'] === (int) $task['parts_total'],
            $task['parts_done'] . '/' . $task['parts_total']);
    }
    check('没有残留的「本地未删除」告警', ($task['purge_error'] ?? null) === null, (string) ($task['purge_error'] ?? ''));
}

// 列表接口也要能读到这条
$statsNow = \App\Service\S3TaskLog::stats();
check('汇总里今日成功数 >= 1', (int) $statsNow['ok_today'] >= 1, (string) $statsNow['ok_today']);

// ------------------------------------------------------------------ 播放

section('3. 播放链路');

$streamUrl = $BASE . '/stream/' . $epId . '?token=' . rawurlencode($token);
[$code, $hdrs] = http('GET', $streamUrl);
$loc = (string) ($hdrs['location'] ?? '');
check('/stream 返回 302 跳远端', $code === 302 && $loc !== '', "HTTP {$code}");
info('→ ' . substr($loc, 0, 110));

$pubBase = S3::publicBase();
$urlMode = (string) Setting::get('s3_url_mode', 'public');
if ($urlMode === 'public') {
    check('跳转目标是公开直链', $pubBase !== '' && str_starts_with($loc, $pubBase . '/'), $urlMode);
}

[$c2, , $blob] = http('GET', $loc, ['timeout' => 180]);
check('跟随直链取回 200', $c2 === 200, "HTTP {$c2}");
check('字节与源文件哈希一致', hash('sha256', $blob) === $sha, strlen($blob) . ' bytes');

[$c3, $h3, $part] = http('GET', $loc, ['headers' => ['Range: bytes=1000-1999']]);
check(
    '直链支持 Range（206 + Content-Range）',
    $c3 === 206 && isset($h3['content-range']),
    "HTTP {$c3} " . ($h3['content-range'] ?? '-')
);
check('Range 返回 1000 字节', strlen($part) === 1000, strlen($part) . ' bytes');

// dl=1 走预签名
[$c4, $h4] = http('GET', $streamUrl . '&dl=1');
$locDl = (string) ($h4['location'] ?? '');
check('dl=1 返回 302', $c4 === 302, "HTTP {$c4}");
check('dl=1 用的是预签名直链', str_contains($locDl, 'X-Amz-Signature='));
if ($locDl !== '') {
    [$c5, , $blobDl] = http('GET', $locDl, ['timeout' => 180]);
    check('预签名直链可取回字节', $c5 === 200 && hash('sha256', $blobDl) === $sha, "HTTP {$c5} " . strlen($blobDl) . ' bytes');
}

// 经 API 查该集的状态（前端「存储」列的数据来源）
[$c6, , $b6] = http('GET', $BASE . '/api/admin/animes/' . $animeId . '/episodes', [
    'headers' => ['Authorization: Bearer ' . $token],
]);
$j6 = json_decode($b6, true);
$mine = null;
foreach (($j6['data']['list'] ?? []) as $rr) {
    if ((int) $rr['id'] === $epId) {
        $mine = $rr;
    }
}
check('后台集数接口返回「仅远程」存储状态', ($mine['storage'] ?? '') === 'remote', (string) ($mine['storage'] ?? '-'));
check('后台集数接口标记 s3_status=1', (int) ($mine['s3_status'] ?? 0) === 1);

// 播放页接口
[$c7, , $b7] = http('GET', $BASE . '/api/episodes/' . $epId, [
    'headers' => ['Authorization: Bearer ' . $token],
]);
$j7 = json_decode($b7, true);
check('播放页接口可用', $c7 === 200 && (int) ($j7['code'] ?? -1) === 0);
// 响应里集数在 data.episode（前端 Player.vue 也取这个字段来决定是否显示「远程」标记）
check('播放页标记 remote=true（前端据此显示远程标记）', ($j7['data']['episode']['remote'] ?? null) === true);
check(
    '播放页标记 storage=remote',
    ($j7['data']['episode']['storage'] ?? '') === 'remote',
    (string) ($j7['data']['episode']['storage'] ?? '-')
);

// ------------------------------------------------------------------ 清理

section('4. 清理');

$del = false;
try {
    $del = $s3->delete($s3Key);
} catch (\Throwable $e) {
    info('删除远端异常：' . $e->getMessage());
}
check('远端对象已删除', $del);
Database::run('DELETE FROM `episode` WHERE `anime_id` = :a', ['a' => $animeId]);
Database::run('DELETE FROM `anime` WHERE `id` = :a', ['a' => $animeId]);
foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);
$s3Key = '';   // 已删，shutdown 不必再删
check('测试剧集已删除', Database::val('SELECT 1 FROM `anime` WHERE `id` = :a', ['a' => $animeId]) === null);
check('临时目录已删除', !is_dir($dir));

echo "\n" . str_repeat('=', 58) . "\n";
printf("  真实环境往返结果：%d/%d 通过\n", $pass, $pass + $fail);
if ($fail > 0) {
    echo "  失败 {$fail} 项\n";
}
echo str_repeat('=', 58) . "\n";

exit($fail > 0 ? 1 : 0);
