<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Service\MediaProbe;
use App\Service\RemuxService;
use App\Service\SubtitleService;

final class AnimeController
{
    /** GET /api/animes */
    public function index(): void
    {
        $page    = max(1, Request::int('page', 1));
        $per     = min(60, max(1, Request::int('per_page', 24)));
        $kw      = Request::str('keyword');
        $year    = Request::int('year', 0);
        $season  = Request::int('season', 0);
        $status  = Request::int('status', -1);
        $favOnly = Request::bool('fav');
        $order   = Request::str('order', 'updated');

        $uid   = Auth::id();
        $where = ['1=1'];
        $bind  = ['uid' => $uid];

        if ($kw !== '') {
            $where[] = '(a.`title` LIKE :kw OR a.`title_cn` LIKE :kw OR a.`alias` LIKE :kw)';
            $bind['kw'] = '%' . $kw . '%';
        }
        if ($year > 0) {
            $where[] = 'a.`year` = :year';
            $bind['year'] = $year;
        }
        if ($season > 0) {
            $where[] = 'a.`season` = :season';
            $bind['season'] = $season;
        }
        if ($status >= 0) {
            $where[] = 'a.`status` = :st';
            $bind['st'] = $status;
        }

        $favJoin = '';
        if ($favOnly && $uid > 0) {
            $favJoin = 'JOIN `user_favorite` ff ON ff.`anime_id` = a.`id` AND ff.`user_id` = :uid';
        }

        $orderBy = match ($order) {
            'title' => 'a.`title` ASC',
            'year'  => 'a.`year` DESC, a.`season` DESC, a.`id` DESC',
            'hot'   => 'a.`ep_count` DESC, a.`updated_at` DESC',
            default => 'a.`updated_at` DESC, a.`id` DESC',
        };

        $whereSql = implode(' AND ', $where);

        // 统计查询不含 :uid 子查询；若未开收藏筛选，:uid 占位符不存在，
        // 传进去会触发 PDO HY093（参数数量不匹配），故单独剔除。
        $countBind = $bind;
        if (!$favOnly || $uid <= 0) {
            unset($countBind['uid']);
        }

        $total = (int) Database::val("SELECT COUNT(*) FROM `anime` a {$favJoin} WHERE {$whereSql}", $countBind);
        $offset = ($page - 1) * $per;

        $rows = Database::all(
            "SELECT a.`id`, a.`title`, a.`title_cn`, a.`cover`, a.`year`, a.`season`,
                    a.`status`, a.`ep_count`, a.`last_ep`, a.`updated_at`,
                    LEFT(COALESCE(a.`summary`,''), 120) AS `summary`,
                    (SELECT COUNT(*) FROM `user_favorite` f2
                      WHERE f2.`anime_id` = a.`id` AND f2.`user_id` = :uid) AS `is_fav`
             FROM `anime` a
             {$favJoin}
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$per} OFFSET {$offset}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['id']       = (int) $r['id'];
            $r['year']     = $r['year'] !== null ? (int) $r['year'] : null;
            $r['season']   = $r['season'] !== null ? (int) $r['season'] : null;
            $r['ep_count'] = (int) $r['ep_count'];
            $r['is_fav']   = (int) $r['is_fav'] === 1;
            $r['season_cn'] = self::seasonCn($r['season']);
        }
        unset($r);

        Response::ok([
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil($total / $per),
        ]);
    }

    /** GET /api/animes/{id} */
    public function show(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $uid = Auth::id();

        $anime = Database::one(
            'SELECT a.*, (SELECT COUNT(*) FROM `user_favorite` f
                          WHERE f.`anime_id` = a.`id` AND f.`user_id` = :uid) AS `is_fav`
             FROM `anime` a WHERE a.`id` = :id LIMIT 1',
            ['id' => $id, 'uid' => $uid]
        );
        if ($anime === null) {
            Response::notFound('番剧不存在');
        }

        $episodes = Database::all(
            'SELECT `id`,`ep_no`,`title`,`file_size`,`subgroup`,`resolution`,
                    `subtitle_lang`,`views`,`created_at`
             FROM `episode` WHERE `anime_id` = :id
             ORDER BY `ep_no` ASC, `id` ASC',
            ['id' => $id]
        );

        // 当前用户的观看进度
        $progress = [];
        if ($uid > 0 && $episodes) {
            $rows = Database::all(
                'SELECT `episode_id`,`position_sec`,`duration_sec`,`finished`
                 FROM `user_history` WHERE `user_id` = :u AND `anime_id` = :a',
                ['u' => $uid, 'a' => $id]
            );
            foreach ($rows as $r) {
                $progress[(int) $r['episode_id']] = [
                    'position_sec' => (int) $r['position_sec'],
                    'duration_sec' => (int) $r['duration_sec'],
                    'finished'     => (int) $r['finished'] === 1,
                ];
            }
        }

        foreach ($episodes as &$e) {
            $e['id']        = (int) $e['id'];
            $e['ep_no']     = (float) $e['ep_no'];
            $e['file_size'] = (int) $e['file_size'];
            $e['views']     = (int) $e['views'];
            $e['playable']  = self::isBrowserPlayable((string) ($e['title'] ?? ''));
            $e['progress']  = $progress[$e['id']] ?? null;
        }
        unset($e);

        $anime['id']       = (int) $anime['id'];
        $anime['year']     = $anime['year'] !== null ? (int) $anime['year'] : null;
        $anime['season']   = $anime['season'] !== null ? (int) $anime['season'] : null;
        $anime['ep_count'] = (int) $anime['ep_count'];
        $anime['is_fav']   = (int) $anime['is_fav'] === 1;
        $anime['season_cn'] = self::seasonCn($anime['season']);

        Response::ok(['anime' => $anime, 'episodes' => $episodes]);
    }

    /** GET /api/episodes/{id} —— 播放页用：单集 + 所属番剧 + 前后集 + 我的进度 */
    public function episode(array $args): void
    {
        $id  = (int) ($args['id'] ?? 0);
        $uid = Auth::id();

        $ep = Database::one(
            'SELECT `id`,`anime_id`,`ep_no`,`title`,`file_path`,`file_size`,`infohash`,`subgroup`,
                    `resolution`,`vcodec`,`acodec`,`pix_fmt`,`sub_tracks`,`probe_at`,
                    `sub_extract_at`,`sub_extract_err`,`remux_path`,`remux_err`,
                    `subtitle_lang`,`views`,`created_at`,
                    `s3_key`,`s3_size`,`s3_status`,`s3_purged_at`,`s3_remote_only`
             FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
        if ($ep === null) {
            Response::notFound('剧集不存在');
        }

        // 本地文件是否还在。上传到对象存储并校验通过后本地文件会被删掉，
        // 这时 remux / 探测都不该再跑（源文件已不存在）。
        $localOrig  = (string) $ep['file_path'] !== '' && is_file((string) $ep['file_path']);
        $localRemux = (string) ($ep['remux_path'] ?? '') !== '' && is_file((string) $ep['remux_path']);
        $hasLocal   = $localOrig || $localRemux;

        // 首次播放时补一次编码探测，结果入库复用；探测失败不阻断播放。
        // 本地文件已被对象存储接管时跳过：没有源文件可探，探测只会白跑一次 ffprobe。
        if ($ep['probe_at'] === null && $hasLocal) {
            MediaProbe::probeEpisode($id);
            $fresh = Database::one(
                'SELECT `vcodec`,`acodec`,`pix_fmt`,`sub_tracks`,`probe_at`
                 FROM `episode` WHERE `id` = :id LIMIT 1',
                ['id' => $id]
            );
            if ($fresh !== null) {
                $ep = array_merge($ep, $fresh);
            }
        }

        // 非原生容器（mkv/avi/ts）还没 remux → 现在做（-c copy，一集约 4 秒）。
        // 做完播放就是原生 MP4，绕开前端解封装的音频 gap；失败则回退前端引擎，不阻断。
        if ($hasLocal
            && ($ep['remux_path'] ?? null) === null
            && !MediaProbe::isNativeContainer(basename((string) $ep['file_path']))
        ) {
            $rr = RemuxService::remux($id);
            if ($rr['ok'] && $rr['path'] !== null) {
                $ep['remux_path'] = $rr['path'];
                $ep['remux_err']  = null;
                $localRemux       = true;
            } elseif ($rr['error'] !== null) {
                $ep['remux_err'] = $rr['error'];
            }
        }

        $animeId = (int) $ep['anime_id'];
        $anime = Database::one(
            'SELECT `id`,`title`,`title_cn`,`cover`,`year`,`season`,`status`,`ep_count`
             FROM `anime` WHERE `id` = :id LIMIT 1',
            ['id' => $animeId]
        );
        if ($anime === null) {
            Response::notFound('番剧不存在');
        }

        $siblings = Database::all(
            'SELECT `id`,`ep_no`,`title`,`resolution`
             FROM `episode` WHERE `anime_id` = :a
             ORDER BY `ep_no` ASC, `id` ASC',
            ['a' => $animeId]
        );

        $prev = null;
        $next = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) {
                $prev = $siblings[$i - 1] ?? null;
                $next = $siblings[$i + 1] ?? null;
                break;
            }
        }

        $progress = null;
        if ($uid > 0) {
            $progress = Database::one(
                'SELECT `position_sec`,`duration_sec`,`finished`
                 FROM `user_history` WHERE `user_id` = :u AND `episode_id` = :e LIMIT 1',
                ['u' => $uid, 'e' => $id]
            );
        }

        $ep['ep_no']     = (float) $ep['ep_no'];
        $ep['file_size'] = (int) $ep['file_size'];
        $ep['views']     = (int) $ep['views'];
        $ep['sub_tracks'] = (int) ($ep['sub_tracks'] ?? 0);

        // ------------------------------------------------------------------
        // 播放通路判定：**必须基于「实际会被发送出去的那个文件」**的扩展名。
        //
        // 本地文件被对象存储接管后，真正发出去的是远端对象，它的扩展名可能和 file_path
        // 完全不同（上传的是服务端 remux 出来的 MP4，而 file_path 还是原始的 .mkv）。
        // 早期版本在没有本地文件时仍拿 file_path 取后缀，于是把一个 MP4 报成 MKV，
        // 前端拿 mkv 解封装器去解 MP4 字节流 —— 必然播放失败，而报错完全指不到这里。
        // 同理，remuxed 也要按「发出去的那个文件」判断，否则后台会把已优化的集显示成「原始」。
        // ------------------------------------------------------------------
        $s3Key    = (string) ($ep['s3_key'] ?? '');
        $origName = basename((string) $ep['file_path']);
        // 本地已无副本、只能走远端直链
        $remote   = $s3Key !== '' && !$hasLocal;

        if ($hasLocal) {
            // 本地优先用 remux 产物（原生可播），没有才用原始文件
            $remuxed  = $localRemux;
            $fileName = basename($remuxed ? (string) $ep['remux_path'] : (string) $ep['file_path']);
        } elseif ($remote) {
            $fileName = basename($s3Key);
            // 远端对象是原生容器、而原始源不是 → 它就是服务端 remux 出来的产物
            $remuxed  = MediaProbe::isNativeContainer($fileName)
                && ($origName === '' || !MediaProbe::isNativeContainer($origName));
        } else {
            $remuxed  = false;
            $fileName = $origName;
        }
        unset($ep['file_path'], $ep['remux_path']);

        $ep['ext']      = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $ep['remuxed']  = $remuxed;
        $ep['playable'] = MediaProbe::isNativeContainer($fileName);
        // 该集是否只能走远程（本地已删除或从未落地），前端据此显示「远程」标记
        $ep['remote']   = $remote;
        $ep['storage']  = $hasLocal ? ($remote ? 'both' : 'local') : ($remote ? 'remote' : 'missing');
        // 只暴露「远端有多大」这种展示信息，对象键与上传状态对前端没用
        $ep['remote_size'] = (int) $ep['s3_size'];
        unset($ep['s3_key'], $ep['s3_size'], $ep['s3_status'], $ep['s3_purged_at'], $ep['s3_remote_only']);
        $ep['playback'] = MediaProbe::playbackPlan([
            'file_name' => $fileName,
            'vcodec'    => $ep['vcodec'],
            'acodec'    => $ep['acodec'],
            'pix_fmt'   => $ep['pix_fmt'],
            'probe_at'  => $ep['probe_at'],
        ]);

        // 已抽好的服务端字幕轨（没有就是空数组，前端据此决定要不要按需抽取）
        $ep['subtitles'] = self::subtitlePayload($id);
        // extracted_at 有值说明服务端已经尝试过：哪怕零轨，前端也不该再退回
        // 前端内嵌扫描那条 19 分钟的路（位图字幕扫到底也是白扫）。
        $ep['subtitle_state'] = [
            'embedded'     => (int) $ep['sub_tracks'],
            'extracted_at' => $ep['sub_extract_at'],
            'error'        => $ep['sub_extract_err'],
        ];
        unset($ep['sub_extract_at'], $ep['sub_extract_err']);

        Response::ok([
            'episode'  => $ep,
            'anime'    => $anime,
            'prev'     => $prev,
            'next'     => $next,
            'siblings' => $siblings,
            'progress' => $progress,
        ]);
    }

    // ------------------------------------------------------------------
    // 字幕
    // ------------------------------------------------------------------

    /** GET /api/episodes/{id}/subtitles —— 已有字幕轨清单 */
    public function subtitles(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $ep = Database::one(
            'SELECT `id`,`sub_tracks`,`sub_extract_at`,`sub_extract_err` FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
        if ($ep === null) {
            Response::notFound('剧集不存在');
        }

        Response::ok([
            'tracks'       => self::subtitlePayload($id),
            'embedded'     => (int) $ep['sub_tracks'],
            'extracted_at' => $ep['sub_extract_at'],
            'error'        => $ep['sub_extract_err'],
        ]);
    }

    /**
     * POST /api/episodes/{id}/subtitles/extract —— 按需抽取。
     *
     * 只解封装不解视频码，实测单集约 0.5 秒，同步做掉最省事：
     * 前端在播放页打开时调一次，返回时字幕已就绪，不必轮询。
     * 并发由 SubtitleService 里的 flock 挡住，多来几个请求只会拿到 busy。
     */
    public function extractSubtitles(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            Response::error('缺少剧集 id', 400);
        }
        $ep = Database::one('SELECT `id` FROM `episode` WHERE `id` = :id LIMIT 1', ['id' => $id]);
        if ($ep === null) {
            Response::notFound('剧集不存在');
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $r = SubtitleService::extract($id, Request::bool('force', false));

        Response::ok([
            'tracks' => self::subtitlePayload($id),
            'busy'   => (bool) $r['busy'],
            'reason' => $r['reason'],
            'error'  => $r['error'],
        ]);
    }

    /** 字幕轨对外结构；url 不带 token，由前端拼上（token 不入缓存与日志） */
    private static function subtitlePayload(int $episodeId): array
    {
        $out = [];
        foreach (SubtitleService::tracks($episodeId) as $t) {
            $kind = (string) ($t['kind'] ?? 'text') === 'image' ? 'image' : 'text';
            $out[] = [
                'index'    => (int) $t['track_index'],
                'label'    => (string) ($t['label'] ?? ''),
                'language' => (string) ($t['language'] ?? ''),
                'codec'    => (string) ($t['codec'] ?? ''),
                'kind'     => $kind,
                'cues'     => (int) $t['cues'],
                'bytes'    => (int) $t['bytes'],
                // 文本轨直接给 VTT；图片轨给时间+位置清单，图片本身按清单里的文件名再取
                'url'      => $kind === 'image'
                    ? sprintf('/subtitle/%d/%d/manifest.json', $episodeId, (int) $t['track_index'])
                    : sprintf('/subtitle/%d/%d.vtt', $episodeId, (int) $t['track_index']),
            ];
        }
        return $out;
    }

    /** GET /api/animes/meta —— 筛选器用的年份/季度/统计 */
    public function meta(): void
    {
        $years = Database::all(
            'SELECT `year`, COUNT(*) AS c FROM `anime`
             WHERE `year` IS NOT NULL GROUP BY `year` ORDER BY `year` DESC LIMIT 30'
        );
        Response::ok([
            'years'  => array_map(static fn($r) => ['year' => (int) $r['year'], 'count' => (int) $r['c']], $years),
            'seasons' => [
                ['value' => 1, 'label' => '春季'],
                ['value' => 2, 'label' => '夏季'],
                ['value' => 3, 'label' => '秋季'],
                ['value' => 4, 'label' => '冬季'],
            ],
            'stats' => [
                'anime'    => (int) Database::val('SELECT COUNT(*) FROM `anime`'),
                'episode'  => (int) Database::val('SELECT COUNT(*) FROM `episode`'),
                'size'     => (int) Database::val('SELECT COALESCE(SUM(`file_size`),0) FROM `episode`'),
            ],
        ]);
    }

    /** GET /api/search?q= —— 轻量搜索建议 */
    public function search(): void
    {
        $q = Request::str('q');
        if (mb_strlen($q) < 1) {
            Response::ok(['list' => []]);
        }
        $rows = Database::all(
            'SELECT `id`,`title`,`title_cn`,`cover`,`year`,`ep_count`
             FROM `anime`
             WHERE `title` LIKE :q OR `title_cn` LIKE :q OR `alias` LIKE :q
             ORDER BY `updated_at` DESC LIMIT 20',
            ['q' => '%' . $q . '%']
        );
        Response::ok(['list' => $rows]);
    }

    /** GET /api/home —— 首页：追番有更新的 + 最近入库 */
    public function home(): void
    {
        $uid = Auth::id();

        $recent = Database::all(
            'SELECT `id`,`title`,`title_cn`,`cover`,`year`,`season`,`ep_count`,`last_ep`,`updated_at`
             FROM `anime` WHERE `updated_at` IS NOT NULL
             ORDER BY `updated_at` DESC LIMIT 18'
        );

        $favUpdates = [];
        if ($uid > 0) {
            $favUpdates = Database::all(
                'SELECT a.`id`, a.`title`, a.`title_cn`, a.`cover`, a.`ep_count`, a.`updated_at`,
                        COALESCE(h.`max_ep`, 0) AS `watched_ep`,
                        a.`last_ep`
                 FROM `user_favorite` f
                 JOIN `anime` a ON a.`id` = f.`anime_id`
                 LEFT JOIN (
                     -- user_history 只存 episode_id，集数需从 episode 取
                     SELECT h2.`anime_id`, MAX(e2.`ep_no`) AS `max_ep`
                     FROM `user_history` h2
                     JOIN `episode` e2 ON e2.`id` = h2.`episode_id`
                     WHERE h2.`user_id` = :u2
                     GROUP BY h2.`anime_id`
                 ) h ON h.`anime_id` = a.`id`
                 WHERE f.`user_id` = :u
                 ORDER BY a.`updated_at` DESC LIMIT 18',
                ['u' => $uid, 'u2' => $uid]
            );
        }

        Response::ok([
            'recent'      => $recent,
            'fav_updates' => $favUpdates,
            'stats'       => [
                'anime'   => (int) Database::val('SELECT COUNT(*) FROM `anime`'),
                'episode' => (int) Database::val('SELECT COUNT(*) FROM `episode`'),
            ],
        ]);
    }

    // ------------------------------------------------------------------

    private static function seasonCn(?int $s): ?string
    {
        return match ($s) {
            1 => '春', 2 => '夏', 3 => '秋', 4 => '冬', default => null,
        };
    }

    /** 浏览器原生能否播放该容器（mkv 不行，需前端解封装） */
    public static function isBrowserPlayable(string $filename): bool
    {
        return MediaProbe::isNativeContainer($filename);
    }
}
