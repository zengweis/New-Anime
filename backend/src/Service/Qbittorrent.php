<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Http;
use App\Core\Setting;

/**
 * qBittorrent WebUI API v2 客户端。
 * 认证：优先 SID cookie；qB 若开启 LocalHostAuth=false，局域网内无需登录。
 */
final class Qbittorrent
{
    private string $base;
    private string $user;
    private string $pass;
    private ?string $sid = null;
    private bool $loggedIn = false;
    private int $timeout = 20;

    public function __construct(?string $base = null, ?string $user = null, ?string $pass = null)
    {
        $this->base = rtrim($base ?? (string) Setting::get('qb_url', 'http://127.0.0.1:8080'), '/');
        $this->user = $user ?? (string) Setting::get('qb_user', 'admin');
        $this->pass = $pass ?? (string) Setting::get('qb_pass', '');
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    // ------------------------------------------------------------------
    //  底层请求
    // ------------------------------------------------------------------

    /**
     * @param array{body?:mixed,timeout?:int} $opt
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    private function req(string $method, string $path, array $opt = [], bool $canRetryAuth = true): array
    {
        $opt += ['timeout' => $this->timeout];
        $headers = $opt['headers'] ?? [];
        // qB 的 CSRF / Host 校验要求这两个头
        $headers[] = 'Referer: ' . $this->base;
        $headers[] = 'Origin: ' . $this->base;
        if ($this->sid !== null) {
            $headers[] = 'Cookie: SID=' . $this->sid;
        }
        $opt['headers'] = $headers;

        $r = Http::request($method, $this->base . '/api/v2' . $path, $opt);

        // 捕获 SID
        foreach ($r['headers'] as $h) {
            if (stripos($h, 'set-cookie:') === 0 && preg_match('/SID=([^;]+)/i', $h, $m)) {
                $this->sid = trim($m[1]);
            }
        }

        $unauthorized = $r['status'] === 403
            || $r['status'] === 401
            || stripos($r['body'], 'Forbidden') !== false;

        if ($unauthorized && $canRetryAuth && !$this->loggedIn) {
            $this->login();
            return $this->req($method, $path, $opt, false);
        }

        return [
            'ok'     => $r['ok'],
            'status' => $r['status'],
            'body'   => $r['body'],
            'error'  => $r['error'],
        ];
    }

    public function login(): bool
    {
        $r = Http::post($this->base . '/api/v2/auth/login', [
            'body'    => ['username' => $this->user, 'password' => $this->pass],
            'headers' => ['Referer: ' . $this->base, 'Origin: ' . $this->base],
            'timeout' => 15,
        ]);
        foreach ($r['headers'] as $h) {
            if (stripos($h, 'set-cookie:') === 0 && preg_match('/SID=([^;]+)/i', $h, $m)) {
                $this->sid = trim($m[1]);
            }
        }
        $this->loggedIn = trim($r['body']) === 'Ok.';
        return $this->loggedIn;
    }

    /** 探活：返回版本号，失败抛异常 */
    public function version(): string
    {
        $r = $this->req('GET', '/app/version');
        if (!$r['ok'] || trim($r['body']) === '') {
            throw new \RuntimeException('无法连接 qBittorrent: HTTP ' . $r['status'] . ' ' . $r['error']);
        }
        return trim($r['body'], "\" \t\n");
    }

    // ------------------------------------------------------------------
    //  种子操作
    // ------------------------------------------------------------------

    /** 新建分类（已存在不报错） */
    public function ensureCategory(string $name): void
    {
        if ($name === '') {
            return;
        }
        $this->req('POST', '/torrents/createCategory', ['body' => ['category' => $name]]);
    }

    /**
     * 添加磁力任务。
     * @return array{ok:bool,msg:string,hash:?string}
     */
    public function add(string $magnet, string $savepath = '', string $category = ''): array
    {
        if (stripos($magnet, 'magnet:') !== 0) {
            return ['ok' => false, 'msg' => '不是合法的磁力链接', 'hash' => null];
        }
        $body = ['urls' => $magnet];
        if ($savepath !== '') {
            $body['savepath'] = $savepath;
            $body['autoTMM']  = 'false';
        }
        if ($category !== '') {
            $body['category'] = $category;
        }

        $r = $this->req('POST', '/torrents/add', ['body' => $body, 'timeout' => 30]);
        $txt = trim($r['body']);
        $hash = TitleParser::infohashFromMagnet($magnet);

        if (!$r['ok']) {
            return ['ok' => false, 'msg' => 'qB 返回 HTTP ' . $r['status'] . ' ' . $r['error'], 'hash' => null];
        }
        if (stripos($txt, 'Fails') !== false) {
            // qB 对「已存在」和「链接失效」都回 Fails。查一次任务列表再定性，
            // 否则同一集被重复触发时会一直被记成失败，状态永远好不了。
            if ($hash !== null && $this->has($hash)) {
                return ['ok' => true, 'duplicate' => true, 'msg' => '该任务已在 qBittorrent 中', 'hash' => $hash];
            }
            return ['ok' => false, 'msg' => 'qB 拒绝该任务（链接失效或保存路径不可写）', 'hash' => null];
        }

        return [
            'ok'   => true,
            'msg'  => '已提交下载',
            'hash' => $hash,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function torrents(string $category = '', string $filter = ''): array
    {
        $q = [];
        if ($category !== '') {
            $q['category'] = $category;
        }
        if ($filter !== '') {
            $q['filter'] = $filter;
        }
        $path = '/torrents/info' . ($q ? '?' . http_build_query($q) : '');
        $r = $this->req('GET', $path);
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /**
     * 该 infohash 是否已在 qB 中。
     * qB 只认 40 位十六进制，传 base32 永远查不到，所以先归一化。
     */
    public function has(string $hash): bool
    {
        $hex = TitleParser::normalizeInfohash($hash);
        if ($hex === null || strlen($hex) !== 40) {
            return false;
        }
        $r = $this->req('GET', '/torrents/info?' . http_build_query(['hashes' => $hex]));
        if (!$r['ok']) {
            return false;
        }
        $d = json_decode($r['body'], true);
        return is_array($d) && count($d) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function files(string $hash): array
    {
        $r = $this->req('GET', '/torrents/files?' . http_build_query(['hash' => $hash]));
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    public function properties(string $hash): array
    {
        $r = $this->req('GET', '/torrents/properties?' . http_build_query(['hash' => $hash]));
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /** 全局传输状态 */
    public function transfer(): array
    {
        $r = $this->req('GET', '/transfer/info');
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /** 删除任务 */
    public function delete(array $hashes, bool $deleteFiles = false): bool
    {
        if (!$hashes) {
            return true;
        }
        $r = $this->req('POST', '/torrents/delete', [
            'body' => [
                'hashes'      => implode('|', $hashes),
                'deleteFiles' => $deleteFiles ? 'true' : 'false',
            ],
        ]);
        return $r['ok'];
    }

    /**
     * 暂停（停止）任务。
     *
     * **端点名随 qB 版本而变，必须做兜底**：
     *   - 基于 libtorrent 2.x 的构建用 `/torrents/stop`（qB 4.6 起的主流写法）
     *   - 基于 libtorrent 1.2 的构建仍只有 `/torrents/pause`
     * 本项目实测 qB 4.6.3（Ubuntu 24.04 源的构建）**只认 `/pause`**，
     * 直接打 `/stop` 会返回 404，而 404 在调用方看起来就是「操作失败」——
     * 于是「暂停做种」这个功能一直静默失效。两个端点都试一遍，谁通用谁。
     */
    public function pause(array $hashes): bool
    {
        return $this->stopOrStart('/torrents/stop', '/torrents/pause', $hashes);
    }

    /** 继续（启动）任务。端点同样做版本兜底，见 pause() 的说明 */
    public function resume(array $hashes): bool
    {
        return $this->stopOrStart('/torrents/start', '/torrents/resume', $hashes);
    }

    /**
     * 先打新版端点，404（说明该版本没这个端点）再打旧版端点。
     *
     * @param array<int,string> $hashes
     */
    private function stopOrStart(string $modern, string $legacy, array $hashes): bool
    {
        if (!$hashes) {
            return true;
        }
        $body = ['hashes' => implode('|', $hashes)];

        $r = $this->req('POST', $modern, ['body' => $body]);
        if ($r['ok']) {
            return true;
        }
        // 只有「端点不存在」才值得回退；其它错误（鉴权、参数）回退也没用
        if ((int) ($r['status'] ?? 0) === 404) {
            $r = $this->req('POST', $legacy, ['body' => $body]);
        }
        return $r['ok'];
    }

    /**
     * 判断任务是否已下载完成。
     */
    public static function isFinished(array $t): bool
    {
        $progress = (float) ($t['progress'] ?? 0);
        $state    = (string) ($t['state'] ?? '');

        if ($progress < 0.9999) {
            return false;
        }
        $doneStates = [
            'uploading', 'stalledUP', 'queuedUP', 'forcedUP',
            'pausedUP', 'stoppedUP', 'checkingUP', 'moving',
        ];
        if (in_array($state, ['error', 'missingFiles', 'unknown'], true)) {
            return false;
        }
        return in_array($state, $doneStates, true) || $progress >= 0.9999;
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'error'        => '出错',
            'missingFiles' => '文件缺失',
            'uploading'    => '做种中',
            'stalledUP'    => '做种中',
            'queuedUP'     => '排队做种',
            'forcedUP'     => '强制做种',
            'pausedUP'     => '已暂停',
            'stoppedUP'    => '已停止',
            'checkingUP'   => '校验中',
            'downloading'  => '下载中',
            'stalledDL'    => '等待中',
            'metaDL'       => '获取元数据',
            'queuedDL'     => '排队下载',
            'checkingDL'   => '校验中',
            'allocating'   => '分配空间',
            'moving'       => '移动中',
            default        => $state,
        };
    }
}
