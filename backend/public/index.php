<?php
declare(strict_types=1);

/**
 * anime-new API 入口
 * 所有 /api/* 请求都落到这里，由 Router 分发。
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Controller\AdminController;
use App\Controller\AdminSettingController;
use App\Controller\AnimeController;
use App\Controller\AuthController;
use App\Controller\DanmakuController;
use App\Controller\HealthController;
use App\Controller\MeController;
use App\Core\Log;
use App\Core\Response;
use App\Core\Router;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$debug = (bool) cfg('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

// ---------------------------------------------------------------- CORS
$cors   = (array) cfg('cors', []);
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && (in_array('*', $cors, true) || in_array($origin, $cors, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------ 兜底
set_exception_handler(static function (Throwable $e) use ($debug): void {
    Log::write('error', $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
        'uri'  => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    Response::json([
        'code' => 500,
        'msg'  => $debug ? $e->getMessage() : '服务器内部错误',
        'data' => null,
    ], 500);
});

// ------------------------------------------------------------ 路由
$r = new Router();

$r->get('/api/health', [HealthController::class, 'index']);

// ---- 认证 ----
$r->group('/api/auth', static function (Router $r): void {
    $r->get('/config',           [AuthController::class, 'config']);
    $r->post('/register',        [AuthController::class, 'register']);
    $r->post('/login',           [AuthController::class, 'login']);
    $r->post('/verify-email',    [AuthController::class, 'verifyEmail']);
    $r->post('/resend-verify',   [AuthController::class, 'resendVerify']);
    $r->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    $r->post('/reset-password',  [AuthController::class, 'resetPassword']);
});

$r->group('/api/auth', static function (Router $r): void {
    $r->get('/me',               [AuthController::class, 'me']);
    $r->post('/change-password', [AuthController::class, 'changePassword']);
}, 'user');

// ---- 内容（公开，但带 token 时可返回个人状态） ----
$r->group('/api', static function (Router $r): void {
    $r->get('/home',         [AnimeController::class, 'home']);
    $r->get('/animes',       [AnimeController::class, 'index']);
    $r->get('/animes/meta',  [AnimeController::class, 'meta']);   // 必须早于 /animes/{id}
    $r->get('/animes/{id}',  [AnimeController::class, 'show']);
    $r->get('/episodes/{id}',[AnimeController::class, 'episode']);
    $r->get('/episodes/{id}/subtitles',[AnimeController::class, 'subtitles']);
    $r->get('/episodes/{id}/danmaku',  [DanmakuController::class, 'index']);
    $r->get('/search',       [AnimeController::class, 'search']);
    $r->get('/wishes',       [MeController::class, 'wishes']);     // 许愿墙（公开可看）
});

// ---- 字幕抽取（要登录：会真的跑 ffmpeg，不能让匿名请求触发）----
$r->group('/api', static function (Router $r): void {
    $r->post('/episodes/{id}/subtitles/extract', [AnimeController::class, 'extractSubtitles']);
    $r->post('/episodes/{id}/danmaku', [DanmakuController::class, 'create']);   // 发弹幕（要登录）
    $r->post('/wishes', [MeController::class, 'createWish']);   // 许愿（要登录）
}, 'user');

// ---- 个人 ----
$r->group('/api/me', static function (Router $r): void {
    $r->get('/favorites',        [MeController::class, 'favorites']);
    $r->post('/favorites/{id}',  [MeController::class, 'addFavorite']);
    $r->delete('/favorites/{id}',[MeController::class, 'removeFavorite']);
    $r->get('/history',          [MeController::class, 'history']);
    $r->post('/progress',        [MeController::class, 'reportProgress']);
    $r->get('/continue',         [MeController::class, 'continueWatching']);
    $r->get('/stats',            [MeController::class, 'stats']);
}, 'user');

// ---- 后台 ----
$r->group('/api/admin', static function (Router $r): void {
    $r->get('/overview', [AdminController::class, 'overview']);
    $r->get('/logs',     [AdminController::class, 'logs']);

    // 番剧
    $r->get('/animes',         [AdminController::class, 'animes']);
    $r->post('/animes',        [AdminController::class, 'createAnime']);
    $r->put('/animes/{id}',    [AdminController::class, 'updateAnime']);
    $r->delete('/animes/{id}', [AdminController::class, 'deleteAnime']);
    $r->get('/animes/{id}/episodes', [AdminController::class, 'animeEpisodes']);
    $r->post('/animes/{id}/backfill', [AdminController::class, 'backfillAnime']);
    $r->post('/animes/{id}/backfill-remote', [AdminController::class, 'backfillRemote']);

    // 许愿墙
    $r->get('/wishes',                     [AdminController::class, 'wishes']);
    $r->post('/wishes/limit',              [AdminController::class, 'setWishLimit']);
    $r->post('/wishes/{id}/resolve',       [AdminController::class, 'resolveWish']);
    $r->delete('/wishes/{id}',             [AdminController::class, 'deleteWish']);

    // 弹幕（/episodes 必须早于无后缀的列表路由，避免被当成分页参数）
    $r->get('/danmaku/episodes',  [DanmakuController::class, 'adminEpisodes']);
    $r->get('/danmaku',           [DanmakuController::class, 'adminIndex']);
    $r->post('/danmaku/delete',   [DanmakuController::class, 'adminDelete']);
    $r->post('/danmaku/restore',  [DanmakuController::class, 'adminRestore']);
    $r->post('/danmaku/import',   [DanmakuController::class, 'adminImport']);

    // 规则
    $r->get('/rules',          [AdminController::class, 'rules']);
    $r->post('/rules',         [AdminController::class, 'createRule']);
    $r->post('/rules/preview', [AdminController::class, 'previewRule']);
    $r->post('/rules/suggest', [AdminController::class, 'suggestRule']);
    $r->put('/rules/{id}',     [AdminController::class, 'updateRule']);
    $r->delete('/rules/{id}',  [AdminController::class, 'deleteRule']);
    $r->post('/rules/{id}/download', [AdminController::class, 'downloadRule']);

    // RSS 源
    $r->get('/feeds',          [AdminController::class, 'feeds']);
    $r->post('/feeds',         [AdminController::class, 'createFeed']);
    $r->post('/feeds/fetch',   [AdminController::class, 'fetch']);
    $r->put('/feeds/{id}',     [AdminController::class, 'updateFeed']);
    $r->delete('/feeds/{id}',  [AdminController::class, 'deleteFeed']);

    // RSS 条目
    $r->get('/items',                    [AdminController::class, 'items']);
    $r->post('/items/quick-create',      [AdminController::class, 'quickCreate']);
    $r->post('/items/batch-download',    [AdminController::class, 'batchDownload']);
    $r->post('/items/{id}/download',     [AdminController::class, 'downloadItem']);
    $r->post('/items/{id}/ignore',       [AdminController::class, 'ignoreItem']);
    $r->post('/items/{id}/assign',       [AdminController::class, 'assignItem']);

    // 下载 / 同步
    $r->get('/torrents',           [AdminController::class, 'torrents']);
    $r->post('/torrents/action',   [AdminController::class, 'torrentAction']);
    $r->post('/torrents/pause-finished', [AdminController::class, 'pauseFinished']);
    $r->post('/sync',              [AdminController::class, 'sync']);
    $r->post('/sync/refresh',      [AdminController::class, 'refreshAll']);

    // 设置
    $r->get('/settings',            [AdminSettingController::class, 'getSettings']);
    $r->put('/settings',            [AdminSettingController::class, 'saveSettings']);
    $r->post('/settings/smtp-test', [AdminSettingController::class, 'smtpTest']);
    $r->post('/settings/s3-test',   [AdminSettingController::class, 's3Test']);
    $r->get('/settings/s3-stats',   [AdminSettingController::class, 's3Stats']);

    // 对象存储（S3 / R2）
    $r->get('/s3/objects',              [AdminController::class, 's3Objects']);
    $r->post('/s3/upload-batch',        [AdminController::class, 's3UploadBatch']);
    $r->post('/s3/upload/{id}',         [AdminController::class, 's3Upload']);
    $r->post('/s3/unlink/{id}',         [AdminController::class, 's3Unlink']);
    $r->get('/animes/{id}/remote-candidates', [AdminController::class, 'remoteCandidates']);

    // 上传任务监控
    $r->get('/s3/tasks',                [AdminController::class, 's3Tasks']);
    $r->post('/s3/tasks/retry/{id}',    [AdminController::class, 's3TaskRetry']);
    $r->post('/s3/tasks/clear',         [AdminController::class, 's3TaskClear']);

    // 用户
    $r->get('/users',                 [AdminSettingController::class, 'users']);
    $r->post('/users',                [AdminSettingController::class, 'createUser']);
    $r->post('/users/{id}/status',    [AdminSettingController::class, 'setUserStatus']);
    $r->post('/users/{id}/role',      [AdminSettingController::class, 'setUserRole']);
    $r->delete('/users/{id}',         [AdminSettingController::class, 'deleteUser']);

    // 邀请码
    $r->get('/invites',             [AdminSettingController::class, 'invites']);
    $r->post('/invites/batch',      [AdminSettingController::class, 'createInvites']);
    $r->post('/invites/{id}/toggle',[AdminSettingController::class, 'toggleInvite']);
    $r->delete('/invites/{id}',     [AdminSettingController::class, 'deleteInvite']);

    // 邮件队列
    $r->get('/mails',        [AdminSettingController::class, 'mails']);
    $r->post('/mails/retry', [AdminSettingController::class, 'retryMails']);
}, 'admin');

$r->dispatch();
