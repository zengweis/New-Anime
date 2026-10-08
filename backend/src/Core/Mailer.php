<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 手写 SMTP 客户端（零依赖）。
 * 支持 ssl(465) / starttls(587) / 明文，AUTH LOGIN。
 */
final class Mailer
{
    /** @var resource|null */
    private $sock = null;
    private array $trace = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $secure,
        private string $user,
        private string $pass,
        private string $from,
        private string $fromName = '',
        private int $timeout = 20,
    ) {
    }

    public static function fromSetting(): self
    {
        return new self(
            (string) Setting::get('smtp_host', ''),
            (int) Setting::get('smtp_port', 465),
            strtolower((string) Setting::get('smtp_secure', 'ssl')),
            (string) Setting::get('smtp_user', ''),
            (string) Setting::get('smtp_pass', ''),
            (string) Setting::get('smtp_from', ''),
            (string) Setting::get('smtp_from_name', 'Anime New'),
        );
    }

    public function trace(): array
    {
        return $this->trace;
    }

    public function connect(): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('未配置 SMTP 服务器地址');
        }
        $scheme = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);
        $sock = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$sock) {
            throw new \RuntimeException("SMTP 连接失败 {$this->host}:{$this->port} — {$errstr} ({$errno})");
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, $this->timeout);

        $this->read();               // 220 greeting
        $ehlo = $this->cmd('EHLO ' . $this->clientHost());

        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS 协商失败');
            }
            $ehlo = $this->cmd('EHLO ' . $this->clientHost());
            $this->trace[] = 'STARTTLS ok';
        }

        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN');
            $this->cmd(base64_encode($this->user));
            $this->cmd(base64_encode($this->pass));
            $this->trace[] = 'AUTH ok';
        }

        unset($ehlo);
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * 发送一封 HTML 邮件。
     */
    public function send(string $toEmail, string $toName, string $subject, string $html): void
    {
        $this->assertEmail($toEmail);
        $from = $this->from !== '' ? $this->from : $this->user;
        $this->assertEmail($from);

        // 连接复用：正常发送后不关闭；一旦出错则重连重试一次
        $attempt = 0;
        while (true) {
            try {
                if (!$this->sock) {
                    $this->connect();
                }
                $this->cmd('MAIL FROM:<' . $from . '>');
                $this->cmd('RCPT TO:<' . $toEmail . '>');
                $this->cmd('DATA');

                $data = $this->buildMessage($from, $toEmail, $toName, $subject, $html);
                // 点填充，防止正文行首的 . 被当作结束标记
                $data = preg_replace('/^\./m', '..', $data);
                fwrite($this->sock, $data . "\r\n.\r\n");
                $this->read();
                $this->trace[] = 'sent -> ' . $toEmail;
                return;
            } catch (\Throwable $e) {
                $this->close();
                if ($attempt >= 1) {
                    throw $e;
                }
                $attempt++;
                $this->trace[] = 'retry after: ' . $e->getMessage();
            }
        }
    }

    private function buildMessage(
        string $from,
        string $to,
        string $toName,
        string $subject,
        string $html
    ): string {
        $encode = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $fromName = $this->fromName !== '' ? $encode($this->fromName) . ' ' : '';
        $toHeader = $toName !== '' ? $encode($toName) . ' <' . $to . '>' : $to;

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromName . '<' . $from . '>',
            'To: ' . $toHeader,
            'Subject: ' . $encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientHost() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: anime-new',
        ];

        $body = chunk_split(base64_encode($html), 76, "\r\n");
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function clientHost(): string
    {
        $h = parse_url((string) (Setting::get('site_url', '') ?: 'localhost'), PHP_URL_HOST);
        return $h ?: 'localhost';
    }

    private function assertEmail(string $e): void
    {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $e)) {
            throw new \RuntimeException('非法邮箱地址: ' . $e);
        }
    }

    private function cmd(string $line): string
    {
        if (!$this->sock) {
            throw new \RuntimeException('SMTP 未连接');
        }
        if ($line !== '') {
            fwrite($this->sock, $line . "\r\n");
            if (strlen($line) < 40 || !str_starts_with($line, 'AUTH ')) {
                $this->trace[] = '>> ' . substr($line, 0, 60);
            }
        }
        return $this->read();
    }

    private function read(): string
    {
        if (!$this->sock) {
            throw new \RuntimeException('SMTP 未连接');
        }
        $data = '';
        while (($line = fgets($this->sock, 2048)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($data === '') {
            throw new \RuntimeException('SMTP 无响应（超时或连接被关闭）');
        }
        $code = (int) substr($data, 0, 3);
        $this->trace[] = '<< ' . trim(substr($data, 0, 120));
        if ($code >= 400) {
            throw new \RuntimeException('SMTP ' . trim($data));
        }
        return $data;
    }
}
