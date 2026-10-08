<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 极简 S3 客户端（手写 AWS Signature V4），面向 Cloudflare R2 / AWS S3 / MinIO 等
 * 所有 S3 兼容服务。
 *
 * 为什么不用官方 SDK：
 *   本项目没有 composer / vendor 目录，引入 SDK 要连带改自动加载与部署流程。
 *   而我们真正需要的只有 PUT / 分片上传 / GET / HEAD / DELETE / ListObjectsV2，
 *   SigV4 的签名算法是固定的 HMAC 链，手写反而更可控，也不会有依赖升级的负担。
 *
 * 关键实现点：
 *   - **必须分片上传**：单集视频 600MB 级，一次性读进内存会 OOM。这里 >8MB 一律走
 *     CreateMultipartUpload → UploadPart → CompleteMultipartUpload，每片 8MB，
 *     上限 10000 片（≈80GB），失败时 AbortMultipartUpload 清理，不留隐形残片。
 *   - **签名只覆盖 host + x-amz-***：Content-Type 等普通头不参与签名，AWS/R2 都接受，
 *     能避免头部归一化差异导致的 SignatureDoesNotMatch。
 *   - **预签名用 UNSIGNED-PAYLOAD**：私有桶直接给浏览器一个限时直链。
 *   - 寻址方式自动判断：endpoint 主机名以 `{bucket}.` 开头就用 virtual-hosted，
 *     否则用 path-style（R2 / MinIO 的默认形态），无需额外开关。
 */
final class S3
{
    private const ALGO         = 'AWS4-HMAC-SHA256';
    private const SERVICE      = 's3';
    private const UNSIGNED     = 'UNSIGNED-PAYLOAD';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** 分片大小：8MB（R2/S3 最小 5MB 的整数倍，兼顾内存与请求数）。public 供上层预估进度用 */
    public const PART_SIZE = 8 * 1024 * 1024;
    private const MAX_PARTS = 10000;

    private string $endpoint;
    /** 请求基址：只含 `scheme://host[:port]`，**绝不含路径** */
    private string $base;
    /** 签名与 Host 头共用的主机名（含端口） */
    private string $hostHeader;
    /** endpoint 里被忽略掉的路径部分，仅用于提示（S3 的路径就是「桶/键」，不该由 endpoint 提供） */
    private string $endpointPath;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private bool   $pathStyle;
    /** endpoint 主机名里是否已经带了桶名（virtual-hosted 形态） */
    private bool   $bucketInHost;

    private function __construct(array $cfg)
    {
        $this->endpoint  = rtrim((string) $cfg['endpoint'], '/');
        $this->region    = (string) ($cfg['region'] ?: 'auto');
        $this->bucket    = (string) $cfg['bucket'];
        $this->accessKey = (string) $cfg['access_key'];
        $this->secretKey = (string) $cfg['secret_key'];

        $p = parse_url($this->endpoint);
        $scheme = is_array($p) && isset($p['scheme']) ? (string) $p['scheme'] : 'https';
        $host   = is_array($p) && isset($p['host']) ? (string) $p['host'] : '';
        $port   = is_array($p) && isset($p['port']) ? ':' . (int) $p['port'] : '';

        $this->base      = $scheme . '://' . $host . $port;
        $this->hostHeader = $host . $port;
        // 用户在控制台复制 endpoint 时经常把桶名一起带上（`https://…/my-bucket`）。
        // 这个路径必须丢掉：桶名由 uriFor() 负责放进路径，两处都放就会变成
        // `/bucket/bucket/key`，而签名里只写了 `/bucket/key` —— 于是 R2 报
        // SignatureDoesNotMatch，报错完全指不到真正的原因。
        $this->endpointPath = trim((string) (is_array($p) ? ($p['path'] ?? '') : ''), '/');

        // endpoint 已经写成 `桶名.xxx` 就是 virtual-hosted 形态，此时桶名不再进路径，
        // 也**不能**在主机名上再拼一次（会变成 桶名.桶名.xxx）。
        $this->bucketInHost = $this->bucket !== '' && str_starts_with($host, $this->bucket . '.');
        $this->pathStyle    = !$this->bucketInHost;
    }

    // ==================================================================
    //  构造 / 配置
    // ==================================================================

    /** 按 setting 表配置构造；未配置完整时返回 null */
    public static function fromSettings(): ?self
    {
        $c = [
            'endpoint'   => trim((string) Setting::get('s3_endpoint', '')),
            'region'     => trim((string) Setting::get('s3_region', 'auto')),
            'bucket'     => trim((string) Setting::get('s3_bucket', '')),
            'access_key' => trim((string) Setting::get('s3_access_key', '')),
            'secret_key' => (string) Setting::get('s3_secret_key', ''),
        ];
        $missing = [];
        foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
            if ($c[$k] === '') {
                $missing[] = $k;
            }
        }
        if ($missing) {
            return null;
        }
        if (!preg_match('#^https?://#i', $c['endpoint'])) {
            $c['endpoint'] = 'https://' . $c['endpoint'];
        }
        return new self($c);
    }

    /** 是否已开启并配置完整 */
    public static function enabled(): bool
    {
        return Setting::get('s3_enabled', '0') === '1' && self::fromSettings() !== null;
    }

    /** 配置缺失时的具体原因，用于前端提示 */
    public static function configHint(): string
    {
        if (Setting::get('s3_enabled', '0') !== '1') {
            return '对象存储未启用';
        }
        if (self::fromSettings() === null) {
            return '对象存储配置不完整（需填 endpoint / bucket / AccessKey / SecretKey）';
        }
        return '';
    }

    /** 对象键前缀（去掉首尾斜杠） */
    public static function prefix(): string
    {
        return trim((string) Setting::get('s3_prefix', 'anime'), '/');
    }

    /** 公开访问基址（自定义域 / r2.dev），未配置则回退到 endpoint+bucket */
    public static function publicBase(): string
    {
        $b = trim((string) Setting::get('s3_public_base', ''));
        if ($b !== '') {
            return rtrim($b, '/');
        }
        $s = self::fromSettings();
        if ($s === null) {
            return '';
        }
        // 公开桶：https://<account>.r2.cloudflarestorage.com/<bucket> 也可直读
        return $s->base . ($s->pathStyle ? '/' . $s->bucket : '');
    }

    /**
     * 生成对象键：{prefix}/{anime_id}/{ep_no}-{文件名}
     * 只保留安全字符，避免签名/URL 编码差异。
     */
    public static function buildKey(int $animeId, float $epNo, string $filename): string
    {
        $clean = preg_replace('/[^\p{Han}A-Za-z0-9._\-\[\]() ]+/u', '_', $filename) ?? 'file';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? 'file');
        $ep    = $epNo > 0 ? rtrim(rtrim(number_format($epNo, 1, '.', ''), '0'), '.') : '0';
        $ep    = str_pad($ep, 3, '0', STR_PAD_LEFT);

        return self::prefix() . '/' . $animeId . '/' . $ep . '-' . mb_substr($clean, 0, 120);
    }

    // ==================================================================
    //  URL
    // ==================================================================

    /** 公开直链（桶为公开读时可用） */
    public function publicUrl(string $key): string
    {
        $base = self::publicBase();
        return $base === '' ? '' : $base . '/' . $this->encodePath(ltrim($key, '/'));
    }

    /**
     * 预签名 GET 直链（桶为私有时用）。
     *
     * @param array<string,string> $respOverrides 形如 ['response-content-disposition' => 'attachment; filename="x.mp4"']
     */
    public function presign(string $key, int $ttl = 3600, array $respOverrides = []): string
    {
        $ttl         = max(60, min(604800, $ttl));
        $encodedPath = $this->encodedPathFor($key);
        $amzDate     = gmdate('Ymd\THis\Z');
        $date        = gmdate('Ymd');
        $scope       = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';

        $query = array_merge($respOverrides, [
            'X-Amz-Algorithm'     => self::ALGO,
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ]);

        $canonicalRequest = implode("\n", [
            'GET',
            $encodedPath,
            $this->canonicalQuery($query),
            // 规范要求 `host:值`，冒号后**不能有空格**。写了空格（`host: 值`）
            // 签名照样算得出来，但远端一律回 403 SignatureDoesNotMatch —— 而且
            // 这里没有任何本地症状，只能靠线下对账才能发现。
            'host:' . $this->host() . "\n",
            'host',
            self::UNSIGNED,
        ]);

        $sig = $this->signature($amzDate, $scope, $canonicalRequest);
        $query['X-Amz-Signature'] = $sig;

        return $this->base . $encodedPath . '?' . $this->rawQuery($query);
    }

    // ==================================================================
    //  对象操作
    // ==================================================================

    /**
     * 上传本地文件。小文件单次 PUT；大文件自动分片。
     *
     * @param callable|null $onProgress function(int $bytesSent, int $partsDone, int $partsTotal, bool $multipart): void
     *                                  每片上传完成后回调，供上层写进度（后台监控页要显示实时进度）
     * @return array{ok:bool,key:?string,bytes:int,etag:?string,multipart:bool,error:?string}
     */
    public function putFile(string $key, string $file, ?string $mime = null, ?callable $onProgress = null): array
    {
        if (!is_file($file)) {
            return self::res(false, null, 0, null, false, '本地文件不存在：' . $file);
        }
        $size = (int) @filesize($file);
        if ($size <= 0) {
            return self::res(false, null, 0, null, false, '本地文件为空');
        }
        $mime = $mime ?: (self::mimeOf($file) ?: 'application/octet-stream');

        if ($size <= self::PART_SIZE) {
            $body = @file_get_contents($file);
            if ($body === false) {
                return self::res(false, null, 0, null, false, '本地文件读取失败');
            }
            if ($onProgress !== null) {
                $onProgress(0, 0, 1, false);
            }
            $r = $this->uploadPart($key, $body, null, null, $mime);
            if ($r['ok'] && $onProgress !== null) {
                $onProgress($size, 1, 1, false);
            }
            return $r['ok']
                ? self::res(true, $key, $size, $r['etag'], false, null)
                : self::res(false, null, 0, null, false, $r['error']);
        }

        return $this->putMultipart($key, $file, $size, $mime, $onProgress);
    }

    /** 分片上传主流程 */
    private function putMultipart(string $key, string $file, int $size, string $mime, ?callable $onProgress = null): array
    {
        $init = $this->initMultipart($key, $mime);
        if (!$init['ok']) {
            return self::res(false, null, 0, null, true, $init['error']);
        }
        $uploadId = (string) $init['upload_id'];

        $totalParts = (int) ceil($size / self::PART_SIZE);
        if ($onProgress !== null) {
            $onProgress(0, 0, $totalParts, true);
        }

        $parts  = [];
        $fp     = @fopen($file, 'rb');
        if ($fp === false) {
            $this->abortMultipart($key, $uploadId);
            return self::res(false, null, 0, null, true, '本地文件打开失败');
        }

        try {
            $num = 0;
            $sent = 0;
            while (!feof($fp)) {
                $num++;
                if ($num > self::MAX_PARTS) {
                    throw new \RuntimeException('分片数超过上限 ' . self::MAX_PARTS);
                }
                $chunk = fread($fp, self::PART_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $sent += strlen($chunk);

                // 单片失败重试 3 次（网络抖动是常态）
                $r = ['ok' => false, 'etag' => null, 'error' => 'unknown'];
                for ($try = 1; $try <= 3; $try++) {
                    $r = $this->uploadPart($key, $chunk, $num, $uploadId, null);
                    if ($r['ok']) {
                        break;
                    }
                    usleep(500000 * $try);
                }
                if (!$r['ok']) {
                    throw new \RuntimeException("第 {$num} 片上传失败：" . $r['error']);
                }
                $parts[] = ['number' => $num, 'etag' => (string) $r['etag']];
                unset($chunk);

                if ($onProgress !== null) {
                    $onProgress($sent, $num, $totalParts, true);
                }
            }

            if ($sent !== $size) {
                throw new \RuntimeException("读取字节数不符（期望 {$size}，实得 {$sent}）");
            }

            $done = $this->completeMultipart($key, $uploadId, $parts);
            if (!$done['ok']) {
                throw new \RuntimeException('合并分片失败：' . $done['error']);
            }
            if ($onProgress !== null) {
                $onProgress($size, count($parts), $totalParts, true);
            }
            return self::res(true, $key, $size, $done['etag'], true, null);
        } catch (\Throwable $e) {
            $this->abortMultipart($key, $uploadId);
            return self::res(false, null, 0, null, true, $e->getMessage());
        } finally {
            fclose($fp);
        }
    }

    /** CreateMultipartUpload */
    private function initMultipart(string $key, string $mime): array
    {
        $r = $this->request('POST', $key, ['uploads' => ''], [], null, ['Content-Type' => $mime], 60);
        if (!$r['ok']) {
            return ['ok' => false, 'upload_id' => null, 'error' => $r['error']];
        }
        if (preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m)) {
            return ['ok' => true, 'upload_id' => $m[1], 'error' => null];
        }
        return ['ok' => false, 'upload_id' => null, 'error' => '响应里没有 UploadId'];
    }

    /** UploadPart */
    private function uploadPart(string $key, string $body, ?int $num, ?string $uploadId, ?string $mime): array
    {
        $query = [];
        if ($num !== null) {
            $query['partNumber'] = (string) $num;
        }
        if ($uploadId !== null) {
            $query['uploadId'] = $uploadId;
        }
        $r = $this->request('PUT', $key, $query, [], $body, $mime !== null ? ['Content-Type' => $mime] : [], 300);
        return [
            'ok'    => $r['ok'],
            'etag'  => $r['headers']['etag'] ?? null,
            'error' => $r['error'],
        ];
    }

    /** CompleteMultipartUpload */
    private function completeMultipart(string $key, string $uploadId, array $parts): array
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $p) {
            $xml .= '<Part><PartNumber>' . (int) $p['number'] . '</PartNumber>'
                . '<ETag>' . htmlspecialchars((string) $p['etag'], ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $r = $this->request('POST', $key, ['uploadId' => $uploadId], [], $xml, ['Content-Type' => 'application/xml'], 300);
        // 合并响应体里若含 <Error> 即失败（HTTP 200 也可能带 Error）
        if ($r['ok'] && stripos($r['body'], '<Error>') !== false) {
            return ['ok' => false, 'etag' => null, 'error' => self::xmlError($r['body'])];
        }
        return ['ok' => $r['ok'], 'etag' => $r['headers']['etag'] ?? null, 'error' => $r['error']];
    }

    /** AbortMultipartUpload（清理，失败也不抛） */
    private function abortMultipart(string $key, string $uploadId): void
    {
        try {
            $this->request('DELETE', $key, ['uploadId' => $uploadId], [], null, [], 30);
        } catch (\Throwable) {
            // 清理失败留待 R2 生命周期规则回收，不影响主流程
        }
    }

    /**
     * HEAD 对象，返回 size / etag；不存在返回 null。
     *
     * @return array{size:int,etag:?string,type:?string}|null
     */
    public function head(string $key): ?array
    {
        $r = $this->request('HEAD', $key, [], [], null, [], 30);
        if ($r['status'] === 404) {
            return null;
        }
        if (!$r['ok']) {
            throw new \RuntimeException('HEAD 失败：' . $r['error']);
        }
        return [
            'size' => (int) ($r['headers']['content-length'] ?? 0),
            'etag' => $r['headers']['etag'] ?? null,
            'type' => $r['headers']['content-type'] ?? null,
        ];
    }

    /** 删除对象。返回 true 表示远端已不存在（含本来就 404） */
    public function delete(string $key): bool
    {
        $r = $this->request('DELETE', $key, [], [], null, [], 60);
        return $r['ok'] || $r['status'] === 404;
    }

    /**
     * ListObjectsV2
     *
     * @return array{ok:bool,objects:array<int,array{key:string,size:int,modified:string,etag:string}>,next:?string,truncated:bool,error:?string}
     */
    public function listObjects(string $prefix = '', ?string $token = null, int $limit = 1000): array
    {
        $limit = max(1, min(1000, $limit));
        $query = ['list-type' => '2', 'max-keys' => (string) $limit];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        if ($token !== null && $token !== '') {
            $query['continuation-token'] = $token;
        }

        $r = $this->request('GET', '', $query, [], null, [], 60);
        if (!$r['ok']) {
            return ['ok' => false, 'objects' => [], 'next' => null, 'truncated' => false, 'error' => $r['error']];
        }

        $xml = self::flatXml($r['body']);
        if ($xml === null) {
            return ['ok' => false, 'objects' => [], 'next' => null, 'truncated' => false, 'error' => '响应不是合法 XML'];
        }

        $objects = [];
        foreach ($xml->Contents ?? [] as $c) {
            $key = (string) $c->Key;
            if ($key === '') {
                continue;
            }
            $objects[] = [
                'key'      => $key,
                'size'     => (int) $c->Size,
                'modified' => (string) $c->LastModified,
                'etag'     => trim((string) $c->ETag, '"'),
            ];
        }

        return [
            'ok'        => true,
            'objects'   => $objects,
            'next'      => ((string) ($xml->NextContinuationToken ?? '')) ?: null,
            'truncated' => strtolower((string) ($xml->IsTruncated ?? 'false')) === 'true',
            'error'     => null,
        ];
    }

    /**
     * 连通性自检。
     *
     * @return array{ok:bool,msg:string,detail:array<string,mixed>}
     */
    public function test(bool $write = false): array
    {
        $start = microtime(true);
        $detail = [
            'endpoint'   => $this->endpoint,
            'base'       => $this->base,
            'bucket'     => $this->bucket,
            'region'     => $this->region,
            'path_style' => $this->pathStyle,
        ];
        $notice = $this->endpointPathNotice();
        if ($notice !== '') {
            $detail['notice'] = $notice;
        }

        $r = $this->listObjects(self::prefix() . '/', null, 1);
        $detail['latency_ms'] = (int) ((microtime(true) - $start) * 1000);
        if (!$r['ok']) {
            $msg = '列举对象失败：' . $r['error'];
            if (str_contains($r['error'], 'SignatureDoesNotMatch')) {
                $msg .= $this->signatureHint();
            }
            return ['ok' => false, 'msg' => $msg, 'detail' => $detail];
        }
        $detail['probe_objects'] = count($r['objects']);

        if (!$write) {
            return ['ok' => true, 'msg' => '连接正常（读权限已通过）', 'detail' => $detail];
        }

        // 写权限：放一个探针对象再删掉
        $probe = self::prefix() . '/.probe-' . bin2hex(random_bytes(6)) . '.txt';
        $body  = 'anime-new s3 probe ' . date('c');
        $tmp   = sys_get_temp_dir() . '/s3probe-' . getmypid() . '.txt';
        @file_put_contents($tmp, $body);
        $p = $this->putFile($probe, $tmp, 'text/plain');
        @unlink($tmp);
        if (!$p['ok']) {
            $msg = '写入失败：' . $p['error'];
            if (str_contains((string) $p['error'], 'SignatureDoesNotMatch')) {
                $msg .= $this->signatureHint();
            }
            return ['ok' => false, 'msg' => $msg, 'detail' => $detail];
        }
        $h = $this->head($probe);
        $detail['probe_size'] = $h['size'] ?? -1;
        $this->delete($probe);

        return ['ok' => true, 'msg' => '连接正常（读写权限均已通过）', 'detail' => $detail];
    }

    /**
     * SignatureDoesNotMatch 的排查提示。
     *
     * 这个错误码是对象存储里最"黑"的一个：远端只说「你给的签名和我算的不一样」，
     * 而原因可能是密钥抄错、endpoint 填法不对、region 不对。把常见原因直接列在
     * 报错里，比让人去翻签名实现高效得多。
     */
    private function signatureHint(): string
    {
        return "\n排查方向（按可能性排序）：\n"
            . "  1. Secret Access Key 是否完整粘贴 —— R2 只在创建 API 令牌时显示一次，长度 64 位十六进制\n"
            . "  2. Access Key ID 是否是该令牌的 ID（既不是「账户 ID」，也不是桶名）\n"
            . "  3. Endpoint 只填到主机名即可，桶名请填在 Bucket 字段；本工具会自动忽略 endpoint 里的路径\n"
            . "  4. Region 填 auto（R2 专用值）\n"
            . "  5. 令牌权限是否包含 Object Read & Write";
    }

    // ==================================================================
    //  签名与传输
    // ==================================================================

    /**
     * 请求要发往的主机名（不含协议）。
     *
     * 两种形态下主机名都等于 endpoint 自身的主机名：
     *   - path-style（默认）：桶名进路径 `/桶名/键`
     *   - virtual-hosted：桶名本来就在 endpoint 主机名里，路径不要再带桶名
     * 所以这里**不做任何拼接**——早期版本在 virtual-hosted 分支又拼了一次桶名，
     * 结果是 `桶名.桶名.xxx`，远端只会回 403 SignatureDoesNotMatch。
     */
    private function host(): string
    {
        return $this->hostHeader;
    }

    /** 请求用的编码后路径：签名与 URL **必须共用同一份字符串** */
    private function encodedPathFor(string $key): string
    {
        return $this->encodePath($this->uriFor($key));
    }

    /** endpoint 里带了路径时给出的提示（用于自检结果展示） */
    private function endpointPathNotice(): string
    {
        if ($this->endpointPath === '') {
            return '';
        }
        return $this->endpointPath === $this->bucket
            ? "Endpoint 末尾多了 /{$this->endpointPath}，已自动忽略（桶名填在 Bucket 字段即可）"
            : "Endpoint 里的路径 /{$this->endpointPath} 已自动忽略（S3 的路径由「桶/键」组成）";
    }

    private function uriFor(string $key): string
    {
        $key = ltrim($key, '/');
        if ($key === '') {
            // 桶级操作（ListObjectsV2）
            return $this->pathStyle ? '/' . $this->bucket : '/';
        }
        return $this->pathStyle ? '/' . $this->bucket . '/' . $key : '/' . $key;
    }

    /** 逐段编码，保留 `/`；S3 要求路径不得二次编码 */
    private function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn(string $s): string => rawurlencode($s),
            explode('/', $path)
        ));
    }

    private function canonicalQuery(array $q): string
    {
        $q = array_filter($q, static fn($v): bool => $v !== null);
        ksort($q, SORT_STRING);
        $out = [];
        foreach ($q as $k => $v) {
            $out[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $out);
    }

    private function rawQuery(array $q): string
    {
        return $this->canonicalQuery($q);
    }

    private function signature(string $amzDate, string $scope, string $canonicalRequest): string
    {
        $stringToSign = implode("\n", [
            self::ALGO,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $date   = substr($amzDate, 0, 8);
        $kDate  = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kReg   = hash_hmac('sha256', $this->region, $kDate, true);
        $kSvc   = hash_hmac('sha256', self::SERVICE, $kReg, true);
        $kSign  = hash_hmac('sha256', 'aws4_request', $kSvc, true);

        return hash_hmac('sha256', $stringToSign, $kSign);
    }

    /**
     * 发一个已签名的请求。
     *
     * @param array<string,string> $query
     * @param array<string,string> $extraHeaders 不参与签名（如 Content-Type）
     * @return array{ok:bool,status:int,body:string,error:string,headers:array<string,string>}
     */
    private function request(
        string $method,
        string $key,
        array $query,
        array $signed,
        ?string $body,
        array $extraHeaders = [],
        int $timeout = 60
    ): array {
        $payloadHash = $body === null || $body === '' ? self::EMPTY_SHA256 : hash('sha256', $body);
        $amzDate     = gmdate('Ymd\THis\Z');
        $encodedPath = $this->encodedPathFor($key);

        $material = $this->sign($method, $encodedPath, $query, $signed, $payloadHash, $amzDate);

        // URL 复用与签名完全相同的 $encodedPath，且基址不含路径。
        // 这样「签的路径」与「发的路径」在结构上不可能不一致。
        $url = $this->base . $encodedPath;
        if ($query !== []) {
            $url .= '?' . $this->rawQuery($query);
        }

        $headers = [];
        foreach ($material['signed_headers'] as $k => $v) {
            // host 交给 curl 自己发（它会从 URL 推导），手动发在 HTTP/2 下会冲突；
            // 但 canonical headers 里必须保留 host —— 那是签名的一部分。
            if (strtolower($k) === 'host') {
                continue;
            }
            $headers[] = $k . ': ' . $v;
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $headers[] = 'Authorization: ' . $material['authorization'];

        return $this->curl($method, $url, $headers, $body, $timeout);
    }

    /**
     * 构造 SigV4 签名材料。
     *
     * 独立成一个方法是为了可测：`bin/s3_sigtest.php` 用反射调它，把
     * canonical request / string to sign / signature 跟 botocore（AWS 官方参考实现）
     * 逐字段比对。签名算法一旦有偏差，症状是远端直接 403 SignatureDoesNotMatch，
     * 光看报错很难定位到是哪一步规范化写错了。
     *
     * @param array<string,string> $query
     * @param array<string,string> $signed   需额外签名的头（host / x-amz-content-sha256 / x-amz-date 自动带入）
     * @param string $encodedPath **已经 URL 编码**的路径（调用方与 URL 共用同一份，避免两处拼接发散）
     * @return array{authorization:string,signed_headers:array<string,string>,signed_keys:string,canonical_request:string,string_to_sign:string,scope:string}
     */
    private function sign(
        string $method,
        string $encodedPath,
        array $query,
        array $signed,
        string $payloadHash,
        string $amzDate
    ): array {
        $date  = substr($amzDate, 0, 8);
        $scope = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';

        $signedHeaders = array_merge([
            'host'                 => $this->host(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ], $signed);
        ksort($signedHeaders, SORT_STRING);

        $canonicalLines = '';
        foreach ($signedHeaders as $k => $v) {
            $canonicalLines .= strtolower($k) . ':' . preg_replace('/\s+/', ' ', trim((string) $v)) . "\n";
        }
        $signedKeys = implode(';', array_keys($signedHeaders));

        $canonicalRequest = implode("\n", [
            $method,
            $encodedPath,
            $this->canonicalQuery($query),
            $canonicalLines,
            $signedKeys,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [
            self::ALGO,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        return [
            'authorization' => self::ALGO
                . ' Credential=' . $this->accessKey . '/' . $scope
                . ', SignedHeaders=' . $signedKeys
                . ', Signature=' . $this->signature($amzDate, $scope, $canonicalRequest),
            'signed_headers'    => $signedHeaders,
            'signed_keys'       => $signedKeys,
            'canonical_request' => $canonicalRequest,
            'string_to_sign'    => $stringToSign,
            'scope'             => $scope,
        ];
    }

    /** @return array{ok:bool,status:int,body:string,error:string,headers:array<string,string>} */
    private function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch     = curl_init();
        $got    = [];
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$got): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $got[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            // 不跟随重定向：S3 的 301 通常意味着 region / 寻址方式不对，
            // 盲目跟随会把签名带到别的 host 上，报错反而更难懂。
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'anime-new-s3/1.0',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($ch, $opts);

        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $ok = $err === '' && $status >= 200 && $status < 300;
        $error = '';
        if ($err !== '') {
            $error = '网络错误：' . $err;
        } elseif (!$ok) {
            $error = 'HTTP ' . $status . ' ' . self::xmlError(is_string($out) ? $out : '');
        }

        return [
            'ok'      => $ok,
            'status'  => $status,
            'body'    => is_string($out) ? $out : '',
            'error'   => $error,
            'headers' => $got,
        ];
    }

    // ==================================================================
    //  工具
    // ==================================================================

    /** 去掉命名空间前缀，让 simplexml 能直接按标签名取 */
    private static function flatXml(string $body): ?\SimpleXMLElement
    {
        $clean = preg_replace('/\sxmlns(:[\w-]+)?="[^"]*"/', '', $body) ?? $body;
        $clean = preg_replace('#<(/?)[\w-]+:#', '<$1', $clean) ?? $clean;
        $xml = @simplexml_load_string($clean);
        return $xml === false ? null : $xml;
    }

    /** 从 S3 错误响应里提取 Code/Message */
    private static function xmlError(string $body): string
    {
        if ($body === '') {
            return '';
        }
        if (preg_match('#<Code>([^<]+)</Code>#', $body, $c)) {
            $msg = preg_match('#<Message>([^<]+)</Message>#', $body, $m) ? $m[1] : '';
            return trim($c[1] . ($msg !== '' ? ' — ' . $msg : ''));
        }
        return mb_substr(trim(strip_tags($body)), 0, 200);
    }

    private static function mimeOf(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'webm'       => 'video/webm',
            'mkv'        => 'video/x-matroska',
            'avi'        => 'video/x-msvideo',
            'mov'        => 'video/quicktime',
            'ts'         => 'video/mp2t',
            'flv'        => 'video/x-flv',
            'srt', 'ass', 'ssa' => 'text/plain; charset=utf-8',
            default      => '',
        };
    }

    /** @return array{ok:bool,key:?string,bytes:int,etag:?string,multipart:bool,error:?string} */
    private static function res(bool $ok, ?string $key, int $bytes, ?string $etag, bool $mp, ?string $err): array
    {
        return ['ok' => $ok, 'key' => $key, 'bytes' => $bytes, 'etag' => $etag, 'multipart' => $mp, 'error' => $err];
    }
}
