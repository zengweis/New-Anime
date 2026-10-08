<?php
declare(strict_types=1);

/**
 * 字幕分发。
 *   /subtitle/{id}/{track}.vtt?token=xxx                     文本字幕（WebVTT）
 *   /subtitle/{id}/{track}.vtt?token=xxx&dl=1                → 下载 .vtt
 *   /subtitle/{id}/{track}/manifest.json?token=xxx           图片字幕的时间+位置清单
 *   /subtitle/{id}/{track}/00001.webp?token=xxx              图片字幕的某一屏
 *
 * 为什么要单独一个入口：这些地址由浏览器自发请求（<track src> / <img src>），
 * 带不上 Authorization 头，所以令牌只能走 query —— 和 /stream 一致。
 * 文件都很小（几十 KB 的 VTT、几 KB 的图片），直接由 PHP 读出来发即可，不必动用 X-Accel。
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Service\SubtitleService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

function bail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$epId  = (int) ($_GET['id'] ?? 0);
$track = (int) ($_GET['track'] ?? 0);

if ($epId <= 0) {
    bail(400, '缺少剧集 id');
}

// ---- 鉴权（令牌可走 query，因为 <track> 带不了请求头）----
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $_GET['token'] = trim($m[1]);
    }
}
if (Auth::user() === null) {
    bail(401, '未登录或令牌无效');
}

// ---- 取文件：图片字幕走 asset 分支，其余是 VTT ----
$asset = (string) ($_GET['file'] ?? '');
if ($asset !== '') {
    $path = SubtitleService::assetPath($epId, $track, basename($asset));
} else {
    $path = SubtitleService::vttPath($epId, $track);
}
if ($path === null) {
    bail(404, '字幕不存在');
}

$size  = (int) filesize($path);
$mtime = (int) filemtime($path);
$etag  = '"' . md5($path . '|' . $size . '|' . $mtime) . '"';

// ---- 条件请求：字幕是纯静态产物，命中缓存可以直接 304 ----
$inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($inm !== '' && $inm === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

// 图片字幕：清单与图片都是不可变的静态产物，可以放心长缓存
if ($asset !== '') {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = match ($ext) {
        'json' => 'application/json; charset=utf-8',
        'webp' => 'image/webp',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $type);
    header('Content-Length: ' . $size);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$row = Database::one(
    'SELECT `label`,`language`,`codec` FROM `episode_subtitle`
     WHERE `episode_id` = :e AND `track_index` = :t LIMIT 1',
    ['e' => $epId, 't' => $track]
);

$name = sprintf(
    'ep%d.%s%s.vtt',
    $epId,
    (string) ($row['language'] ?? '') !== '' ? (string) $row['language'] . '.' : '',
    preg_replace('/[^\w\-]+/u', '', (string) ($row['label'] ?? '')) ?: 'sub'
);

header('Content-Type: text/vtt; charset=utf-8');
header('Content-Length: ' . $size);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
if (isset($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
}

readfile($path);
exit;
