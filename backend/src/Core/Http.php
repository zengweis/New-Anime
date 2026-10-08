<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP 客户端（curl 封装），用于抓 RSS / 调 qBittorrent API。
 */
final class Http
{
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    /**
     * @param array{
     *   headers?:array, body?:string|array, timeout?:int, follow?:bool,
     *   cookie_jar?:string, ua?:string, retry?:int, proxy?:string
     * } $opt
     * @return array{ok:bool,status:int,body:string,error:string,headers:array}
     */
    public static function request(string $method, string $url, array $opt = []): array
    {
        $retry = max(0, (int) ($opt['retry'] ?? 0));
        $last  = null;

        for ($i = 0; $i <= $retry; $i++) {
            $last = self::once($method, $url, $opt);
            if ($last['ok']) {
                return $last;
            }
            // 网络类错误才重试，4xx 不重试
            if ($last['status'] >= 400 && $last['status'] < 500) {
                return $last;
            }
            if ($i < $retry) {
                usleep(400000 * ($i + 1));
            }
        }
        return $last;
    }

    private static function once(string $method, string $url, array $opt): array
    {
        $ch      = curl_init();
        $headers = $opt['headers'] ?? [];
        $body    = $opt['body'] ?? null;

        if (is_array($body)) {
            $body    = http_build_query($body, '', '&');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $respHeaders = [];
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => (int) ($opt['timeout'] ?? 25),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => (bool) ($opt['follow'] ?? true),
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => $opt['ua'] ?? self::UA,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if (!empty($opt['cookie_jar'])) {
            $jar = $opt['cookie_jar'];
            $opts[CURLOPT_COOKIEJAR]  = $jar;
            $opts[CURLOPT_COOKIEFILE] = $jar;
        }
        if (!empty($opt['proxy'])) {
            $opts[CURLOPT_PROXY] = $opt['proxy'];
        }

        curl_setopt_array($ch, $opts);
        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        // 合并同名 header（多值用 , 连接）
        $flat = [];
        foreach ($respHeaders as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }

        return [
            'ok'      => $err === '' && $status >= 200 && $status < 400,
            'status'  => $status,
            'body'    => is_string($out) ? $out : '',
            'error'   => $err,
            'headers' => $flat,
        ];
    }

    public static function get(string $url, array $opt = []): array
    {
        return self::request('GET', $url, $opt);
    }

    public static function post(string $url, array $opt = []): array
    {
        return self::request('POST', $url, $opt);
    }

    /** JSON 请求，返回解析后的数组或 null */
    public static function json(string $method, string $url, array $opt = []): ?array
    {
        $opt['headers'] = array_merge($opt['headers'] ?? [], ['Content-Type: application/json']);
        $r = self::request($method, $url, $opt);
        if ($r['body'] === '') {
            return null;
        }
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : null;
    }
}
