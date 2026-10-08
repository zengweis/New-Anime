<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Service\DanmakuService;

/**
 * 弹幕接口。
 *
 * 读：公开（播放页本身要登录，但读接口不需要令牌 —— 弹幕是旁路数据，
 *     没必要给它加一层鉴权，也方便以后分享链接时字幕/弹幕都能拿）。
 * 写：必须登录，user_id 落库（业务要求：能追到是哪个人发的哪条）。
 * 管理：列表 / 批量删除 / 导入。
 */
final class DanmakuController
{
    /** GET /api/episodes/{id}/danmaku */
    public function index(array $args): void
    {
        $epId = (int) ($args['id'] ?? 0);
        if (DanmakuService::episode($epId) === null) {
            Response::notFound('剧集不存在');
        }

        $data = DanmakuService::payload($epId);
        $etag = 'W/"dm' . $epId . '-' . $data['sig'] . '"';

        $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && $inm === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        $body = json_encode([
            'code' => 0,
            'msg'  => 'ok',
            'data' => ['items' => $data['items'], 'total' => $data['total']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = is_string($body) ? $body : '{"code":1,"msg":"序列化失败","data":null}';

        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // nginx 的 gzip_types 没开，application/json 不会被压缩。弹幕文本重复度高
        // （「哈哈」「前方高能」反复出现），自己压一遍能把 240KB 降到 40KB 上下。
        // 压不动或者客户端不接受就原样发，功能不受影响。
        $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
        if (str_contains($accept, 'gzip') && function_exists('gzencode')) {
            $gz = gzencode($body, 6);
            if (is_string($gz) && strlen($gz) < strlen($body)) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
                header('Content-Length: ' . strlen($gz));
                echo $gz;
                exit;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /** POST /api/episodes/{id}/danmaku */
    public function create(array $args): void
    {
        $epId = (int) ($args['id'] ?? 0);
        $u    = Auth::requireUser();

        try {
            $r = DanmakuService::create(
                $epId,
                (int) $u['id'],
                Request::int('t_ms'),
                Request::str('text'),
                Request::int('mode', 1),
                Request::int('color', 0xFFFFFF),
                Request::int('size', 1),
                Request::ip()
            );
        } catch (\InvalidArgumentException $e) {
            Response::fail($e->getMessage());
        }

        Response::ok($r, '已发送');
    }

    // ------------------------------------------------------------------
    //  后台
    // ------------------------------------------------------------------

    /** GET /api/admin/danmaku */
    public function adminIndex(): void
    {
        Response::ok(DanmakuService::adminList([
            'episode_id' => Request::int('episode_id'),
            'user_id'    => Request::int('user_id'),
            'status'     => Request::str('status', '0'),
            'kw'         => Request::str('kw'),
            'page'       => Request::int('page', 1),
            'per'        => Request::int('per', 50),
        ]));
    }

    /** GET /api/admin/danmaku/episodes —— 下拉框用的剧集与计数 */
    public function adminEpisodes(): void
    {
        Response::ok(['list' => DanmakuService::adminEpisodes()]);
    }

    /** POST /api/admin/danmaku/delete  {ids:[]} */
    public function adminDelete(): void
    {
        $u   = Auth::requireUser();
        $ids = Request::input('ids', []);
        if (!is_array($ids) || $ids === []) {
            Response::fail('没有选中任何弹幕');
        }

        $n = DanmakuService::adminDelete($ids, (int) $u['id']);
        Response::ok(['deleted' => $n], "已删除 {$n} 条");
    }

    /** POST /api/admin/danmaku/restore  {ids:[]} */
    public function adminRestore(): void
    {
        $u   = Auth::requireUser();
        $ids = Request::input('ids', []);
        if (!is_array($ids) || $ids === []) {
            Response::fail('没有选中任何弹幕');
        }

        $n = DanmakuService::adminRestore($ids, (int) $u['id']);
        Response::ok(['restored' => $n], "已恢复 {$n} 条");
    }

    /**
     * POST /api/admin/danmaku/import  {episode_id, text}
     *
     * 走 JSON body 而不是 multipart 上传：nginx 没配 client_max_body_size，
     * 默认 1MB —— 单集弹幕 XML 大多在这个量级以内。更大的文件用 CLI：
     *   php bin/danmaku_import.php <episodeId> <file> --user=<管理员id>
     */
    public function adminImport(): void
    {
        $u    = Auth::requireUser();
        $epId = Request::int('episode_id');
        $text = (string) Request::input('text', '');

        if ($epId <= 0) {
            Response::fail('请先选择要导入到哪一集');
        }
        if (trim($text) === '') {
            Response::fail('没有读到文件内容');
        }

        try {
            $r = DanmakuService::importRaw($epId, (int) $u['id'], $text, Request::ip());
        } catch (\InvalidArgumentException $e) {
            Response::fail($e->getMessage());
        }

        if ($r['parsed'] === 0) {
            Response::fail('这个文件里没有解析出弹幕，请确认是 B 站 XML 或弹幕 JSON');
        }

        Response::ok($r, sprintf('解析 %d 条，写入 %d 条，跳过 %d 条', $r['parsed'], $r['inserted'], $r['skipped']));
    }
}
