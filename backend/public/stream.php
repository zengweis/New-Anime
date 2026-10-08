<?php
declare(strict_types=1);

/**
 * 视频流分发。
 *   /stream/{id}?token=xxx          → 在线播放
 *   /stream/{id}?token=xxx&dl=1     → 强制下载（丢给 PotPlayer/VLC）
 *
 * nginx 下走 X-Accel-Redirect，由 nginx 直接发文件（支持 Range），PHP 只做鉴权。
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\S3;
use App\Core\Setting;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

function bail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$epId = (int) ($_GET['id'] ?? 0);
if ($epId <= 0) {
    bail(400, '缺少剧集 id');
}

// ---- 鉴权（token 可走 query，方便外部播放器） ----
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

// ---- 查库 ----
$ep = Database::one(
    'SELECT `id`,`anime_id`,`ep_no`,`title`,`file_path`,`remux_path`,`s3_key`,`s3_status`
     FROM `episode` WHERE `id` = :id LIMIT 1',
    ['id' => $epId]
);
if ($ep === null) {
    bail(404, '剧集不存在');
}

// ---- 播放计数（去重） ----
// 必须在「本地 / 远程」分流之前：远程播放一样要计数。
// 前端解封装播放会对同一个文件打几十上百个 Range 请求，不能每请求都 +1。
// 同一用户 + 同一剧集在窗口期内只计一次；用唯一键配合条件更新做原子判断，
// 避免「先查后写」的竞态。计次失败绝不影响播放。
try {
    $windowSec = max(60, (int) cfg('play_count_window', 1800));
    $uid       = Auth::id();
    if ($uid > 0) {
        $affected = Database::run(
            'INSERT INTO `play_view` (`user_id`,`episode_id`,`counted_at`)
             VALUES (:u, :e, NOW())
             ON DUPLICATE KEY UPDATE
                 `counted_at` = IF(`counted_at` < NOW() - INTERVAL ' . $windowSec . ' SECOND, NOW(), `counted_at`)',
            ['u' => $uid, 'e' => $epId]
        )->rowCount();

        // 1=首次插入，2=窗口外刷新 → 计一次；0=窗口内重复请求 → 忽略
        if ($affected > 0) {
            Database::run('UPDATE `episode` SET `views` = `views` + 1 WHERE `id` = :id', ['id' => $epId]);
        }
    }
} catch (\Throwable $e) {
    // 统计表缺失或写入失败都不该挡住看片
}

// ---- 优先发 remux 好的 MP4（浏览器原生可播，无音频 gap）；否则发原始文件 ----
$path = (string) $ep['file_path'];
$remux = (string) ($ep['remux_path'] ?? '');
if ($remux !== '' && is_file($remux)) {
    $path = $remux;
}
$root = rtrim((string) Setting::get('download_root', '/data/downloads/anime'), '/');

// ---- 安全：文件必须落在下载根目录内（防目录穿越） ----
$realPath = $path !== '' ? realpath($path) : false;
$realRoot = realpath($root);

$local = $realPath !== false
    && $realRoot !== false
    && ($realPath === $realRoot || str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR))
    && is_file($realPath);

if (!$local) {
    // 本地没有可用文件 → 交给对象存储
    // （本地文件在上传校验通过后被主动删除，这是正常状态，不是错误）
    $s3Key = (string) ($ep['s3_key'] ?? '');
    if ($s3Key === '') {
        // 路径越界属于异常请求，与「文件已删除」区分开
        if ($realPath !== false && $realRoot !== false
            && $realPath !== $realRoot && !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            bail(403, '拒绝访问');
        }
        bail(404, '文件已丢失');
    }

    redirectToS3($s3Key, isset($_GET['dl']), (string) ($_GET['filename'] ?? ''));
}

// ---- 参数 ----
$size = (int) filesize($realPath);
$name = basename($realPath);
$mime = mimeOf($realPath);
$disp = isset($_GET['dl']) ? 'attachment' : 'inline';

// ---- 优先交给 nginx ----
$xaccel = trim((string) Setting::get('xaccel_prefix', '/protected'));
$isNginx = stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'nginx') !== false;

if ($xaccel !== '' && $isNginx) {
    $rel = substr($realPath, strlen($realRoot));
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($name) . '"');
    header('Accept-Ranges: bytes');
    header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . $rel);
    header('X-Accel-Buffering: yes');
    exit;
}

// ---- 回退：PHP 自己发，手写 Range 支持 ----
while (ob_get_level() > 0) {
    ob_end_clean();
}
@set_time_limit(0);
ignore_user_abort(false);

$start = 0;
$end   = $size > 0 ? $size - 1 : 0;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

if ($size > 0 && $range !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int) $m[2]);
        $end   = $size - 1;
    } else {
        $start = (int) $m[1];
        $end   = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($name) . '"');
header('Cache-Control: public, max-age=3600');

$fp = fopen($realPath, 'rb');
if ($fp === false) {
    bail(500, '无法读取文件');
}
fseek($fp, $start);

$remain = $end - $start + 1;
$chunk  = 512 * 1024;

while ($remain > 0 && !feof($fp)) {
    if (connection_aborted()) {
        break;
    }
    $data = fread($fp, (int) min($chunk, $remain));
    if ($data === false || $data === '') {
        break;
    }
    echo $data;
    $remain -= strlen($data);
    flush();
}

fclose($fp);
exit;

// ------------------------------------------------------------------

/**
 * 本地文件已被迁移到对象存储 → 302 跳到远端直链。
 *
 * 两种取址方式：
 *   public    —— 桶公开读，直接给 r2.dev / 自定义域的永久直链，零签名开销。
 *   presigned —— 桶私有，现签一个限时直链（默认 6 小时，够看一集）。
 *
 * `dl=1`（喂给 PotPlayer/VLC）需要 attachment 语义，公开直链做不到，
 * 所以下载场景一律走预签名并带上 response-content-disposition 覆盖。
 * 若签名不可用（没配密钥等），退化成公开直链——至少还能看，只是浏览器可能内联打开。
 */
function redirectToS3(string $key, bool $download, string $filename = ''): never
{
    $mode = (string) Setting::get('s3_url_mode', 'public');
    $s3   = S3::fromSettings();

    $url = '';
    if ($s3 !== null && ($download || $mode === 'presigned')) {
        $ttl  = max(300, (int) Setting::get('s3_presign_ttl', 21600));
        $disp = $download
            ? ($filename !== ''
                ? 'attachment; filename="' . str_replace('"', '', $filename) . '"'
                : 'attachment')
            : '';
        try {
            $url = $s3->presign($key, $ttl, $disp !== '' ? ['response-content-disposition' => $disp] : []);
        } catch (\Throwable) {
            $url = '';
        }
    }

    if ($url === '') {
        $url = $s3 !== null ? $s3->publicUrl($key) : '';
    }
    if ($url === '') {
        bail(502, '远端不可用：对象存储未正确配置');
    }

    // 直链交给浏览器/CDN，不缓存这个跳转本身
    header('Cache-Control: private, no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

function mimeOf(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'mp4', 'm4v' => 'video/mp4',
        'webm'       => 'video/webm',
        'mkv'        => 'video/x-matroska',
        'avi'        => 'video/x-msvideo',
        'mov'        => 'video/quicktime',
        'ts'         => 'video/mp2t',
        'flv'        => 'video/x-flv',
        'ogv'        => 'video/ogg',
        'srt', 'ass', 'ssa' => 'text/plain; charset=utf-8',
        default      => 'application/octet-stream',
    };
}
