<?php
declare(strict_types=1);

/**
 * 对象存储（S3/R2）端到端验收。
 *
 *   php bin/e2e_s3.php <mockBaseUrl> [appBaseUrl]
 *   例：php bin/e2e_s3.php http://127.0.0.1:9100 https://127.0.0.1
 *
 * 配合 tools/s3_mock.py 使用：mock 是一个用 botocore 独立验签的 S3 兼容服务，
 * 所以本脚本跑通 = 「PHP 手写的 SigV4 被官方参考实现接受」+ 「上传/校验/删本地/
 * 302 直链/远程补档 全链路正确」。
 *
 * 覆盖：
 *   1 配置自检（读写探针、预签名可用）
 *   2 单次 PUT（小于分片阈值）
 *   3 分片上传（大于分片阈值，验证 init/part/complete 全流程）
 *   4 上传后回查远端大小一致 → 本地文件被删除、s3_purged_at 落库
 *   5 幂等：重复上传不产生新对象
 *   6 播放：/stream/{id} 302 到公开直链，跟随后的字节与源文件哈希一致
 *   7 下载：dl=1 走预签名（带 X-Amz-Signature），且能取回字节
 *   8 远程列表：新对象出现且标记为已被引用
 *   9 远程补档：只建记录不下载，播放同样 302
 *  10 安全：本地已删的集不允许解绑；purgeLocal 拒绝下载根目录之外的路径
 *  11 失败可观测：endpoint 不可达时落 s3_status=2 且 s3_err 有内容
 *  12 清理：删远端对象 + 删测试剧集 + 还原全部 s3 配置
 *
 * 全部数据自清理，跑完不留痕迹。
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\S3;
use App\Core\Setting;
use App\Service\RemuxService;
use App\Service\S3Uploader;

$MOCK = rtrim((string) ($argv[1] ?? ''), '/');
$BASE = rtrim((string) ($argv[2] ?? 'http://127.0.0.1'), '/');
if ($MOCK === '') {
    exit("用法：php bin/e2e_s3.php <mockBaseUrl> [appBaseUrl]\n");
}

$BUCKET = 'anime-e2e';
$PREFIX = 'e2e-' . date('mdHis');
$AK     = 'AKIAE2EMOCKACCESSKEY';
$SK     = 'e2e-mock-secret-key-0123456789abcdef';

$pass = 0;
$fail = 0;
$notes = [];

// ------------------------------------------------------------------ 断言

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

function note(string $s): void
{
    global $notes;
    $notes[] = $s;
}

/** 详情行（不参与统计） */
function info(string $s): void
{
    echo "        {$s}\n";
}

/** 带令牌的 HTTP 请求；不跟随跳转，返回 [status, headers, body] */
function http(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $hdrs = $opt['headers'] ?? [];
    $got = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int) ($opt['timeout'] ?? 120),
        CURLOPT_FOLLOWLOCATION => (bool) ($opt['follow'] ?? false),
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
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

// ------------------------------------------------------------------ 准备

$admin = Database::one('SELECT * FROM `user` WHERE `role` = "admin" AND `status` = 1 ORDER BY `id` LIMIT 1');
if ($admin === null) {
    exit("没有可用的管理员账号\n");
}
$token = Auth::issue((int) $admin['id'], 'admin');

$root = RemuxService::downloadRoot();
$dir  = $root . '/__e2e_s3_' . date('mdHis');
if (!@mkdir($dir, 0775, true)) {
    exit("无法创建测试目录 {$dir}\n");
}
$animeId   = 0;
$epIds     = [];
$cleanupKeys = [];

/** 原配置快照，用于还原 */
$S3_KEYS = [
    's3_enabled', 's3_endpoint', 's3_region', 's3_bucket', 's3_access_key',
    's3_secret_key', 's3_prefix', 's3_public_base', 's3_url_mode',
    's3_auto_upload', 's3_delete_local', 's3_require_remux', 's3_upload_limit',
];
$snapshot = [];
foreach ($S3_KEYS as $k) {
    $snapshot[$k] = Setting::get($k, null);
}

/**
 * 删掉本次跑出来的远端测试对象。
 *
 * 必须先把**测试配置**装回去再删：测试过程中改过 endpoint（第 11 步故意设成不可达
 * 地址）以及把 s3_enabled 关掉，而原配置本来就是空的 endpoint。如果不重新装一次
 * 测试配置，S3::fromSettings() 直接返回 null，删除静默跳过，桶里就留下测试垃圾。
 */
function purgeRemoteObjects(array $cfg, array $keys): int
{
    foreach ($cfg as $k => $v) {
        try {
            Setting::set($k, $v);
        } catch (\Throwable) {
        }
    }
    $n = 0;
    try {
        $s3 = S3::fromSettings();
        if ($s3 !== null) {
            foreach ($keys as $k) {
                try {
                    if ($s3->delete($k)) {
                        $n++;
                    }
                } catch (\Throwable) {
                }
            }
        }
    } catch (\Throwable) {
    }
    return $n;
}

/** 恢复调用前的配置快照 */
function restoreSettings(array $snapshot): void
{
    foreach ($snapshot as $k => $v) {
        try {
            if ($v === null) {
                Database::run('DELETE FROM `setting` WHERE `k` = :k', ['k' => $k]);
            } else {
                Setting::set($k, $v);
            }
        } catch (\Throwable) {
        }
    }
}

register_shutdown_function(static function () use (&$animeId, &$cleanupKeys, &$cfg, &$snapshot, $dir): void {
    purgeRemoteObjects($cfg, $cleanupKeys);
    restoreSettings($snapshot);
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

echo "对象存储端到端验收\n";
echo "  mock S3 : {$MOCK}\n";
echo "  应用    : {$BASE}\n";
echo "  前缀    : {$PREFIX}\n";

// ------------------------------------------------------------------ 1 配置

section('1. 配置自检');

$cfg = [
    's3_enabled'      => '1',
    's3_endpoint'     => $MOCK,
    's3_region'       => 'auto',
    's3_bucket'       => $BUCKET,
    's3_access_key'   => $AK,
    's3_secret_key'   => $SK,
    's3_prefix'       => $PREFIX,
    's3_public_base'  => $MOCK . '/' . $BUCKET,
    's3_url_mode'     => 'public',
    's3_auto_upload'  => '1',
    's3_delete_local' => '1',
    's3_require_remux' => '0',   // 测试用假文件，跳过 remux 前置
    's3_upload_limit' => '2',
];
foreach ($cfg as $k => $v) {
    Setting::set($k, $v);
}

check('S3::enabled() 为真', S3::enabled());
$s3 = S3::fromSettings();
check('S3::fromSettings() 可用', $s3 !== null);
if ($s3 === null) {
    exit("\n配置不完整，终止\n");
}

$t = $s3->test(true);
check('连通性自检（读 + 写探针）', $t['ok'], $t['ok'] ? '' : $t['msg']);
info('detail: ' . json_encode($t['detail'], JSON_UNESCAPED_SLASHES));
check('公开基址已生成', S3::publicBase() !== '', S3::publicBase());

// ------------------------------------------------------------------ 建测试数据

$animeId = (int) Database::insert('anime', [
    'title'  => '__E2E_S3番剧' . date('mdHis') . '__',
    'year'   => 2026,
    'status' => 1,
]);

/**
 * 造一个可预期哈希的假视频文件（内容按确定性公式生成，便于比对）。
 *
 * 注意：purgeLocal 在删空目录时会把 episode 所在目录一并收掉（这是刻意的清理行为），
 * 所以每次造文件前都要确保目录还在。
 */
function makeVideo(string $path, int $bytes): string
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $h = fopen($path, 'wb');
    if ($h === false) {
        throw new RuntimeException("无法创建测试文件 {$path}（目录存在：" . (is_dir($dir) ? 'yes' : 'no') . '）');
    }
    $written = 0;
    $chunk = '';
    for ($i = 0; $i < 4096; $i++) {
        $chunk .= chr(($i * 7 + 13) % 251);
    }
    while ($written < $bytes) {
        $n = min(strlen($chunk), $bytes - $written);
        fwrite($h, substr($chunk, 0, $n));
        $written += $n;
    }
    fclose($h);
    return hash_file('sha256', $path);
}

function addEpisode(int $animeId, float $epNo, string $title, string $file, int $size): int
{
    return (int) Database::insert('episode', [
        'anime_id'   => $animeId,
        'ep_no'      => $epNo,
        'title'      => $title,
        'file_path'  => $file,
        'file_size'  => $size,
        'vcodec'     => 'h264',
        'acodec'     => 'aac',
        'probe_at'   => date('Y-m-d H:i:s'),
    ]);
}

// 小文件：走单次 PUT
$smallPath = $dir . '/ep01-small.mp4';
$smallSize = 2 * 1024 * 1024;
$smallHash = makeVideo($smallPath, $smallSize);
$epSmall = addEpisode($animeId, 1, 'E2E small.mp4', $smallPath, $smallSize);
$epIds[] = $epSmall;

// 大文件：跨过分片阈值（8MB），走 init/part/complete
$bigPath = $dir . '/ep02-big.mp4';
$bigSize = 20 * 1024 * 1024;
$bigHash = makeVideo($bigPath, $bigSize);
$epBig = addEpisode($animeId, 2, 'E2E big.mp4', $bigPath, $bigSize);
$epIds[] = $epBig;

info('测试数据：小 ' . round($smallSize / 1048576, 2) . 'MB / 大 ' . round($bigSize / 1048576, 2) . 'MB');

// ------------------------------------------------------------------ 2/3 上传

$up = new S3Uploader();

section('2-3. 上传（单次 PUT + 分片）');

$rSmall = $up->upload($epSmall, true);
check('小文件上传成功（单次 PUT）', $rSmall['ok'] && $rSmall['bytes'] === $smallSize, $rSmall['error'] ?: '');
if ($rSmall['key']) {
    $cleanupKeys[] = $rSmall['key'];
}
$big = $rSmall['key'] !== null && $rSmall['key'] !== '';
info('小文件 key: ' . (string) $rSmall['key']);
info('小文件 purged=' . ($rSmall['purged'] ? 'yes' : 'no'));

$rBig = $up->upload($epBig, true);
check('大文件上传成功（分片）', $rBig['ok'] && $rBig['bytes'] === $bigSize, $rBig['error'] ?: '');
if ($rBig['key']) {
    $cleanupKeys[] = $rBig['key'];
}
info('大文件 key: ' . (string) $rBig['key']);

// 远端对象大小（直接问 mock 的状态接口，绕开应用，独立取证）
[, , $stateRaw] = http('GET', $MOCK . '/_mock/state');
$state = json_decode($stateRaw, true) ?: [];
$remoteSizes = $state['object_sizes'] ?? [];
if ($rSmall['key']) {
    check('远端存在小文件且大小一致', ($remoteSizes[$rSmall['key']] ?? -1) === $smallSize, '远端=' . ($remoteSizes[$rSmall['key']] ?? '缺失'));
}
if ($rBig['key']) {
    check('远端存在大文件且大小一致', ($remoteSizes[$rBig['key']] ?? -1) === $bigSize, '远端=' . ($remoteSizes[$rBig['key']] ?? '缺失'));
}

// ------------------------------------------------------------------ 4 删本地

section('4. 上传校验通过后删除本地文件');

$epSmallRow = Database::one('SELECT * FROM `episode` WHERE `id` = :i', ['i' => $epSmall]);
$epBigRow   = Database::one('SELECT * FROM `episode` WHERE `id` = :i', ['i' => $epBig]);

check('小文件已落 s3_status=1', (int) ($epSmallRow['s3_status'] ?? 0) === 1);
check('小文件已落 s3_purged_at', ($epSmallRow['s3_purged_at'] ?? null) !== null);
check('小文件本地已删除', !is_file($smallPath));
check('大文件本地已删除', !is_file($bigPath));
info('purged 时间：' . (string) ($epSmallRow['s3_purged_at'] ?? '-'));

// ------------------------------------------------------------------ 5 幂等

section('5. 幂等：重复上传不产生新对象');

$before = count($remoteSizes);
$again = $up->upload($epSmall, false);
$items = $up->remoteList($PREFIX, null, 100);
check('重复上传被识别为已完成', $again['ok'] && in_array($again['reason'], ['already_uploaded', 'already_remote'], true), 'reason=' . (string) $again['reason']);
check('重复上传没有新增对象', count($items['objects']) === $before, '对象数 ' . count($items['objects']) . ' / 之前 ' . $before);

// ------------------------------------------------------------------ 6 播放直链

section('6. 播放：302 到公开直链，字节一致');

$streamUrl = $BASE . '/stream/' . $epSmall . '?token=' . rawurlencode($token);
[$code, $hdrs, $body] = http('GET', $streamUrl);
check('无跳转请求返回 302', $code === 302, "HTTP {$code}");
$loc = (string) ($hdrs['location'] ?? '');
check('Location 指向对象存储公开直链', $loc !== '' && str_starts_with($loc, $MOCK . '/' . $BUCKET . '/'), $loc);
info('Location: ' . $loc);

// 跟随直链取回全部字节，比对哈希
[$code2, , $blob] = http('GET', $loc, ['follow' => true, 'timeout' => 180]);
check('直链可匿名取回（公开读）', $code2 === 200, "HTTP {$code2}");
check('取回字节与源文件哈希一致', hash('sha256', $blob) === $smallHash, strlen($blob) . ' bytes');

// Range 请求（浏览器拖进度条 / MSE 解封装依赖）
$rangeLoc = $loc;
[$code3, $hdrs3, $part] = http('GET', $rangeLoc, ['headers' => ['Range: bytes=1000-1999'], 'timeout' => 60]);
check('直链支持 Range（206 + Content-Range）', $code3 === 206 && isset($hdrs3['content-range']), "HTTP {$code3} " . ($hdrs3['content-range'] ?? '-'));
check('Range 返回 1000 字节', strlen($part) === 1000, strlen($part) . ' bytes');

// ------------------------------------------------------------------ 7 下载模式

section('7. 下载模式 dl=1 走预签名');

[$code4, $hdrs4] = http('GET', $streamUrl . '&dl=1');
check('dl=1 返回 302', $code4 === 302, "HTTP {$code4}");
$locDl = (string) ($hdrs4['location'] ?? '');
check('下载直链带 SigV4 预签名参数', str_contains($locDl, 'X-Amz-Signature=') && str_contains($locDl, 'X-Amz-Credential='));
check('下载直链带 attachment 语义', str_contains($locDl, 'response-content-disposition'), substr($locDl, 0, 200));
// 预签名 URL 必须自带签名，mock 会验签
[$code5, , $blobDl] = http('GET', $locDl, ['timeout' => 180]);
check('预签名直链可取回字节', $code5 === 200 && hash('sha256', $blobDl) === $smallHash, "HTTP {$code5} " . strlen($blobDl) . ' bytes');

// ------------------------------------------------------------------ 8 远程列表

section('8. 远程文件列表');

$list = $up->remoteList($PREFIX, null, 100);
check('列表返回成功', $list['ok'], (string) ($list['error'] ?? ''));
check('列表含两个对象', count($list['objects']) === 2, count($list['objects']) . ' 个');
$usedCount = 0;
foreach ($list['objects'] as $o) {
    if (!empty($o['used'])) {
        $usedCount++;
    }
}
check('两个对象都被标记为已引用', $usedCount === 2, "{$usedCount} 个有引用");
// 前缀过滤是否生效
$other = $up->remoteList('不存在的前缀', null, 10);
check('前缀过滤生效', count($other['objects']) === 0, count($other['objects']) . ' 个');

// ------------------------------------------------------------------ 9 远程补档

section('9. 远程补档（只建记录，不下载）');

// 补档必须用一个**还没被任何剧集引用**的对象。前面对小/大文件的上传已经把它们的 key
// 绑到各自的集上了，直接拿 $rBig['key'] 补档只会得到「该对象已被引用」。
$importKey = $PREFIX . '/99/remote-only.mp4';
$importSize = 1024 * 1024 + 12345;
$importHash = makeVideo($dir . '/import-only.mp4', $importSize);
$putImport = $s3->putFile($importKey, $dir . '/import-only.mp4');
$cleanupKeys[] = $importKey;
check('准备好一个未被引用的远程对象', $putImport['ok'], (string) ($putImport['error'] ?? ''));
@unlink($dir . '/import-only.mp4');

$remoteEpNo = '3';
$before = (int) Database::val('SELECT COUNT(*) FROM `episode` WHERE `anime_id` = :a', ['a' => $animeId]);
$imp = $up->importRemote($animeId, $importKey, ['ep_no' => $remoteEpNo, 'title' => 'E2E 远程引用.mp4']);
check('远程补档成功', $imp['ok'], (string) ($imp['error'] ?? ''));
if ($imp['episode_id']) {
    $epIds[] = (int) $imp['episode_id'];
}
$after = (int) Database::val('SELECT COUNT(*) FROM `episode` WHERE `anime_id` = :a', ['a' => $animeId]);
check('剧集记录数 +1', $after === $before + 1, "{$before} -> {$after}");

if ($imp['episode_id']) {
    $row = Database::one('SELECT * FROM `episode` WHERE `id` = :i', ['i' => (int) $imp['episode_id']]);
    check('标记为仅远程', (int) $row['s3_remote_only'] === 1);
    check('file_path 为空（本地从未落地）', (string) $row['file_path'] === '');
    check('s3_status 直接为 1', (int) $row['s3_status'] === 1);
    check('远端大小已登记', (int) $row['s3_size'] === $importSize, (string) $row['s3_size'] . ' vs ' . $importSize);

    [$c, $h] = http('GET', $BASE . '/stream/' . (int) $imp['episode_id'] . '?token=' . rawurlencode($token));
    $l = (string) ($h['location'] ?? '');
    check('仅远程的集播放同样 302 到对象存储', $c === 302 && $l !== '', "HTTP {$c} {$l}");
    [$c2, , $b2] = http('GET', $l, ['timeout' => 180]);
    check('补档的集字节与源文件一致', $c2 === 200 && hash('sha256', $b2) === $importHash, "HTTP {$c2}");
}

// 重复引用同一个 key 应被拒
$dup = $up->importRemote($animeId, $importKey, ['ep_no' => '3']);
check('重复引用同一对象被拒绝', !$dup['ok'], (string) ($dup['error'] ?? ''));

// 前缀之外的对象应被拒
$out = $up->importRemote($animeId, 'random/other/file.mp4', ['ep_no' => '9']);
check('前缀之外的对象被拒绝', !$out['ok'], (string) ($out['error'] ?? ''));

// ------------------------------------------------------------------ 10 安全

section('10. 安全边界');

if ($imp['episode_id']) {
    $eid = (int) $imp['episode_id'];
    // 本地已删的集不允许解绑（解绑后就彻底播不了了）
    [$c, , $b] = http('GET', $BASE . '/api/admin/animes/' . $animeId . '/episodes', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $rows = json_decode($b, true);
    $found = null;
    foreach (($rows['data']['list'] ?? []) as $rr) {
        if ((int) $rr['id'] === $eid) {
            $found = $rr;
        }
    }
    check('集数列表接口返回 s3 状态与存储位置', $found !== null && isset($found['storage']), json_encode($found['storage'] ?? null, JSON_UNESCAPED_UNICODE));
    check('存储位置标记为 remote', ($found['storage'] ?? '') === 'remote', (string) ($found['storage'] ?? '-'));

    // 通过 API 解绑 —— 仅远程的集应被拒绝
    [$c2, , $b2] = http('POST', $BASE . '/api/admin/s3/unlink/' . $eid, [
        'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        'body'    => '{}',
    ]);
    $j2 = json_decode($b2, true);
    check('仅远程的集拒绝解绑（防误操作）', $c2 === 200 && (int) ($j2['code'] ?? -1) === 1, 'code=' . ($j2['code'] ?? '?'));
    info('拒绝原因：' . (string) ($j2['msg'] ?? ''));
}

// purgeLocal 不允许删下载根目录之外的文件
$outside = '/tmp/should-not-be-deleted-' . getmypid() . '.txt';
file_put_contents($outside, 'x');
$fakeEp = addEpisode($animeId, 9.5, 'E2E outside', $outside, 1);
$epIds[] = $fakeEp;
$up->purgeLocal($fakeEp);
check('purgeLocal 拒绝删除下载根目录之外的文件', is_file($outside), $outside);
@unlink($outside);

// ------------------------------------------------------------------ 11 失败可观测

section('11. 失败可观测：endpoint 不可达');

Setting::set('s3_endpoint', 'http://127.0.0.1:1');   // 必然拒连
$badPath = $dir . '/ep10-bad.mp4';
makeVideo($badPath, 1024 * 512);
$epBad = addEpisode($animeId, 10, 'E2E bad.mp4', $badPath, 1024 * 512);
$epIds[] = $epBad;
try {
    $upFail = new S3Uploader();
    $rf = $upFail->upload($epBad, true);
    check('上传失败被正确上报', !$rf['ok'], (string) ($rf['error'] ?? ''));
} catch (\Throwable $e) {
    check('上传失败被正确上报', true, '异常被捕获：' . mb_substr($e->getMessage(), 0, 80));
}
$badRow = Database::one('SELECT * FROM `episode` WHERE `id` = :i', ['i' => $epBad]);
check('失败状态落库 s3_status=2', (int) ($badRow['s3_status'] ?? 0) === 2, 'status=' . ($badRow['s3_status'] ?? '?'));
check('失败原因落库 s3_err', ($badRow['s3_err'] ?? '') !== '', mb_substr((string) ($badRow['s3_err'] ?? ''), 0, 70));
check('失败后本地文件仍在（不会丢片）', is_file($badPath));
// 失败重试有上限，避免坏条目永久占队列
check('尝试次数已累加', (int) ($badRow['s3_attempts'] ?? 0) >= 1, 'attempts=' . ($badRow['s3_attempts'] ?? '?'));

// 未配置时优雅降级
Setting::set('s3_enabled', '0');
check('关闭后 S3::enabled() 为假', !S3::enabled());
check('关闭后 config_hint 给出原因', S3::configHint() !== '', S3::configHint());

// ------------------------------------------------------------------ 12 孤儿分片

section('12. 分片上传未留孤儿');

[, , $stateRaw2] = http('GET', $MOCK . '/_mock/state');
$state2 = json_decode($stateRaw2, true) ?: [];
$pending = $state2['pending_uploads'] ?? [];
check('mock 侧没有未完成的分片上传', count($pending) === 0, count($pending) . ' 个残留');
check('mock 验签零失败（PHP 签名被官方实现接受）', (int) ($state2['sig_fail'] ?? -1) === 0, 'sig_fail=' . ($state2['sig_fail'] ?? '?'));
check('确实走到了分片合并（completed >= 1）', (int) ($state2['completed'] ?? 0) >= 1, 'completed=' . ($state2['completed'] ?? '?'));
info('mock 统计：requests=' . ($state2['requests'] ?? '?') . ' sig_ok=' . ($state2['sig_ok'] ?? '?') . ' completed=' . ($state2['completed'] ?? '?'));

// ------------------------------------------------------------------ 13 抢跑防护

section('13. 抢跑防护：本地文件被删前，依赖本地文件的任务必须已收尾');

// (a) 还没探测的集不该被上传：probe_at 为空时 sub_tracks 还是默认 0，
//     与「确实没字幕」无法区分，删早了会连编码信息一起丢掉
$unprobedPath = $dir . '/ep19-unprobed.mkv';
makeVideo($unprobedPath, 512 * 1024);
$epUnprobed = (int) Database::insert('episode', [
    'anime_id'   => $animeId,
    'ep_no'      => 19,
    'title'      => 'E2E 未探测.mkv',
    'file_path'  => $unprobedPath,
    'file_size'  => 512 * 1024,
]);
$epIds[] = $epUnprobed;
$pendIds0 = array_map(static fn(array $r): int => (int) $r['id'], S3Uploader::pending(50));
check('尚未探测的集不出现在上传队列', !in_array($epUnprobed, $pendIds0, true), '待传：' . implode(',', $pendIds0));

Database::run('UPDATE `episode` SET `probe_at` = NOW(), `vcodec` = \'h264\', `acodec` = \'aac\' WHERE `id` = :i', ['i' => $epUnprobed]);
$pendIds0b = array_map(static fn(array $r): int => (int) $r['id'], S3Uploader::pending(50));
check('探测完成后进入上传队列', in_array($epUnprobed, $pendIds0b, true), '待传：' . implode(',', $pendIds0b));

// (b) 字幕还没抽的集也不该被上传（否则内嵌字幕永远抽不出来）
$subPath = $dir . '/ep20-subs.mp4';
makeVideo($subPath, 512 * 1024);
$epSubs = (int) Database::insert('episode', [
    'anime_id'   => $animeId,
    'ep_no'      => 20,
    'title'      => 'E2E 待抽字幕.mp4',
    'file_path'  => $subPath,
    'file_size'  => 512 * 1024,
    'vcodec'     => 'h264',
    'acodec'     => 'aac',
    'probe_at'   => date('Y-m-d H:i:s'),
    'sub_tracks' => 2,
]);
$epIds[] = $epSubs;
$pendIds = array_map(static fn(array $r): int => (int) $r['id'], S3Uploader::pending(50));
check('字幕未抽取的集不出现在上传队列', !in_array($epSubs, $pendIds, true), '待传：' . implode(',', $pendIds));

Database::run('UPDATE `episode` SET `sub_extract_at` = NOW() WHERE `id` = :i', ['i' => $epSubs]);
$pendIds2 = array_map(static fn(array $r): int => (int) $r['id'], S3Uploader::pending(50));
check('字幕抽完（或确认无字幕）后进入上传队列', in_array($epSubs, $pendIds2, true), '待传：' . implode(',', $pendIds2));

// (b) 本地文件已被对象存储接管的集，不该再被 remux / 字幕任务排队
$purged = Database::one('SELECT `id`,`s3_purged_at` FROM `episode` WHERE `id` = :i', ['i' => $epSmall]);
check('已删本地的集带 s3_purged_at 标记', ($purged['s3_purged_at'] ?? null) !== null);

// 造一个「remux 未完成 + 本地已删」的组合，模拟最坏情况
Database::run(
    'UPDATE `episode` SET `remux_path` = NULL, `remux_err` = NULL WHERE `id` = :i',
    ['i' => $epSmall]
);
$remuxPend = array_map(static fn(array $r): int => (int) $r['id'], RemuxService::pending(50));
check('本地已删的集不会被 remux 任务重新排队', !in_array($epSmall, $remuxPend, true), 'remux 队列：' . implode(',', $remuxPend));

$subPend = array_map(static fn(array $r): int => (int) $r['id'], \App\Service\SubtitleService::pending(50));
check('本地已删的集不会被字幕任务重新排队', !in_array($epSmall, $subPend, true), '字幕队列：' . implode(',', $subPend));

// 复原，避免影响前面的结论（这一集还要参与最后的清理）
Database::run(
    'UPDATE `episode` SET `remux_path` = :p WHERE `id` = :i',
    ['p' => $root . '/remux/' . $epSmall . '.mp4', 'i' => $epSmall]
);

// ------------------------------------------------------------------ 14 endpoint 误带桶名

section('14. 回归：Endpoint 末尾误带桶名时仍能正常工作');

// 真实事故：用户从 R2 控制台复制 endpoint 时把桶名也带上了
// （`https://<account>.r2.cloudflarestorage.com/my-bucket`），
// 于是请求路径变成 `/my-bucket/my-bucket/key`，而签名里只写了 `/my-bucket/key`，
// 远端直接回 403 SignatureDoesNotMatch，报错完全指不到真正的原因。
// 现在 endpoint 的路径部分会被忽略（桶名由 Bucket 字段负责）。
Setting::set('s3_endpoint', $MOCK . '/' . $BUCKET);

$s3Dup = S3::fromSettings();
check('带桶名路径的 endpoint 仍能解析出配置', $s3Dup !== null);
$td = $s3Dup->test(true);
check('带桶名路径时连通性自检通过（旧版必然 403）', $td['ok'], $td['ok'] ? '' : $td['msg']);
check(
    '自检结果里说明了路径被忽略',
    str_contains((string) ($td['detail']['notice'] ?? ''), '忽略'),
    (string) ($td['detail']['notice'] ?? '(无提示)')
);
info('base = ' . (string) ($td['detail']['base'] ?? '-'));

// 真跑一次上传，确认发出去的路径与签名一致
$dupPath = $dir . '/ep30-dup-endpoint.mp4';
$dupHash = makeVideo($dupPath, 1024 * 512);
$epDup = addEpisode($animeId, 30, 'E2E endpoint带桶名.mp4', $dupPath, 1024 * 512);
$epIds[] = $epDup;
$upDup = new S3Uploader();
$rDup = $upDup->upload($epDup, true);
check('带桶名路径时上传成功', $rDup['ok'], (string) ($rDup['error'] ?? ''));
if ($rDup['key']) {
    $cleanupKeys[] = $rDup['key'];
}
if ($rDup['key']) {
    [, , $st3] = http('GET', $MOCK . '/_mock/state');
    $s3state = json_decode($st3, true) ?: [];
    check(
        '远端对象大小与本地一致',
        (($s3state['object_sizes'] ?? [])[$rDup['key']] ?? -1) === 1024 * 512,
        (string) (($s3state['object_sizes'] ?? [])[$rDup['key']] ?? '缺失')
    );
}

// 复原为规范写法
Setting::set('s3_endpoint', $MOCK);

// ------------------------------------------------------------------ 汇总

echo "\n" . str_repeat('=', 58) . "\n";
printf("  对象存储验收结果：%d/%d 通过\n", $pass, $pass + $fail);
if ($fail > 0) {
    echo "  失败 {$fail} 项\n";
}
echo str_repeat('=', 58) . "\n";
if ($notes) {
    echo "\n备注：\n";
    foreach ($notes as $n) {
        echo "  - {$n}\n";
    }
}

// 清理：先装回测试配置删远端对象，再还原原配置
echo "\n清理：";
$deleted = purgeRemoteObjects($cfg, $cleanupKeys);
restoreSettings($snapshot);
Database::run('DELETE FROM `episode` WHERE `anime_id` = :a', ['a' => $animeId]);
Database::run('DELETE FROM `anime` WHERE `id` = :a', ['a' => $animeId]);
foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);
echo "远端对象删除 {$deleted} 个，测试剧集与临时目录已删除，配置已还原\n";

exit($fail > 0 ? 1 : 0);
