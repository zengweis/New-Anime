#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SigV4 签名的独立校验（不发任何网络请求）。
 *
 *   php bin/s3_sigtest.php <spec.json>
 *   echo '<spec>' | php bin/s3_sigtest.php -
 *
 * spec 字段：
 *   endpoint / region / bucket / access_key / secret_key / path_style(可选)
 *   method / key / query{}/ signed{}(额外签名头) / body(字符串) / amz_date
 *
 * 输出 JSON：canonical_request / string_to_sign / signed_keys / signature / authorization
 *
 * 为什么需要它：手写 SigV4 一旦规范化写错（路径编码、查询排序、头部小写与空白折叠、
 * 空负载哈希），远端只会回一个 403 SignatureDoesNotMatch，很难判断错在哪一步。
 * 把中间产物全部打出来，就能跟 botocore（AWS 官方参考实现）逐字段对账。
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\S3;

$src = $argv[1] ?? '';
if ($src === '') {
    fwrite(STDERR, "用法：php bin/s3_sigtest.php <spec.json|->\n");
    exit(2);
}
$raw = $src === '-' ? (string) stream_get_contents(STDIN) : (string) file_get_contents($src);
$spec = json_decode($raw, true);
if (!is_array($spec)) {
    fwrite(STDERR, "spec 不是合法 JSON\n");
    exit(2);
}

// 用反射走真实构造函数（构造函数是私有的），这样 endpoint/base/host 的推导逻辑
// 也一并被测到 —— 早期版本绕开构造函数手填字段，结果漏掉了「endpoint 带路径」的推导。
$rc = new ReflectionClass(S3::class);
/** @var S3 $s3 */
$s3 = $rc->newInstanceWithoutConstructor();
$ctor = $rc->getConstructor();
$ctor->setAccessible(true);
$ctor->invoke($s3, [
    'endpoint'   => (string) ($spec['endpoint'] ?? 'https://s3.amazonaws.com'),
    'region'     => (string) ($spec['region'] ?? 'us-east-1'),
    'bucket'     => (string) ($spec['bucket'] ?? ''),
    'access_key' => (string) ($spec['access_key'] ?? ''),
    'secret_key' => (string) ($spec['secret_key'] ?? ''),
]);

// 计算路径（桶级操作 key 传空串）
$uriFor = $rc->getMethod('uriFor');
$path   = (string) $uriFor->invoke($s3, (string) ($spec['key'] ?? ''));
$encodePath = $rc->getMethod('encodePath');
$encodedPath = (string) $encodePath->invoke($s3, $path);

$method = strtoupper((string) ($spec['method'] ?? 'GET'));
$query  = (array) ($spec['query'] ?? []);
$signed = (array) ($spec['signed'] ?? []);
$body   = array_key_exists('body', $spec) ? (string) $spec['body'] : null;
$amzDate = (string) ($spec['amz_date'] ?? gmdate('Ymd\THis\Z'));

$payloadHash = ($body === null || $body === '')
    ? 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
    : hash('sha256', $body);

// sign() 收的是**已编码**的路径（与 request() 里拼 URL 用的是同一份）
$signMethod = $rc->getMethod('sign');
/** @var array $material */
$material = $signMethod->invoke($s3, $method, $encodedPath, $query, $signed, $payloadHash, $amzDate);

$auth = (string) $material['authorization'];
$signature = '';
if (preg_match('/Signature=([0-9a-f]+)/', $auth, $m)) {
    $signature = $m[1];
}

// 请求基址：只含 scheme://host[:port]，endpoint 里的路径会被忽略（桶名另有归属）
$base = (string) $rc->getProperty('base')->getValue($s3);
$url  = $base . $encodedPath;
if ($query !== []) {
    $url .= '?' . (string) $rc->getMethod('canonicalQuery')->invoke($s3, $query);
}

echo json_encode([
    'path'              => $path,
    'url'               => $url,
    'base'              => $base,
    'host'              => (string) $rc->getMethod('host')->invoke($s3),
    'payload_hash'      => $payloadHash,
    'canonical_query'   => (string) $rc->getMethod('canonicalQuery')->invoke($s3, $query),
    'canonical_request' => $material['canonical_request'],
    'string_to_sign'    => $material['string_to_sign'],
    'signed_keys'       => $material['signed_keys'],
    'scope'             => $material['scope'],
    'signature'         => $signature,
    'authorization'     => $auth,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
