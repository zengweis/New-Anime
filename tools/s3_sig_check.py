"""SigV4 签名对账：PHP 手写实现 vs botocore（AWS 官方参考实现）。

在服务器上运行：

    /tmp/s3t/bin/python /opt/anime-new/tools/s3_sig_check.py

为什么要这么测：
    SigV4 一旦有一处规范化写错（路径要不要二次编码、查询参数排序与编码、
    头部小写化与空白折叠、空负载用哪个哈希），远端只会回一个
    403 SignatureDoesNotMatch，从报错里看不出错在哪一步。把 PHP 侧的
    canonical request / string to sign / signature 全部打出来，跟 botocore
    逐字段比对，就能精确定位差异。

退出码非 0 表示有对账不通过的用例。
"""

import datetime
import json
import os
import subprocess
import sys
import tempfile

from botocore.auth import S3SigV4Auth
from botocore.awsrequest import AWSRequest
from botocore.credentials import Credentials

BACKEND = os.environ.get("AN_BACKEND", "/opt/anime-new/backend")
PHP = os.environ.get("AN_PHP", "php")

AK = "AKIAIOSFODNN7EXAMPLE"
SK = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"

# (名称, spec)
# spec 字段与 bin/s3_sigtest.php 一致；path_style 缺省时由 endpoint/bucket 推导
CASES = [
    (
        "GET 桶级 ListObjectsV2（path-style）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "GET",
            "key": "",
            "query": {"list-type": "2", "max-keys": "100", "prefix": "anime/"},
        },
    ),
    (
        "PUT 对象（普通键 + 负载）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "PUT",
            "key": "anime/12/001-Test File (1080p) [HEVC].mp4",
            "query": {},
            "body": "Welcome to Amazon S3.",
        },
    ),
    (
        "PUT 对象（中文 + # & 等需编码字符）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "PUT",
            "key": "anime/7/003-【字幕组】标题 #1 & more~x.mkv",
            "query": {},
            "body": "payload-cn",
        },
    ),
    (
        "POST 初始化分片上传（uploads= 空值参数）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "POST",
            "key": "anime/12/001-x.mp4",
            "query": {"uploads": ""},
        },
    ),
    (
        "PUT 分片（query 值含 Base64 特殊字符）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "PUT",
            "key": "anime/12/001-x.mp4",
            "query": {"partNumber": "1", "uploadId": "Vk1zLmFiYzEyMy+/=XyZ"},
            "body": "part-one-bytes",
        },
    ),
    (
        "POST 合并分片（XML 负载）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "POST",
            "key": "anime/12/001-x.mp4",
            "query": {"uploadId": "abc+/="},
            "body": '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber>'
                    '<ETag>"abc123"</ETag></Part></CompleteMultipartUpload>',
        },
    ),
    (
        "DELETE 对象",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "DELETE",
            "key": "anime/12/001-x.mp4",
            "query": {},
        },
    ),
    (
        "HEAD 对象（无负载）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "HEAD",
            "key": "anime/12/001-x.mp4",
            "query": {},
        },
    ),
    (
        "GET 续取令牌含 = 与 /（query 编码）",
        {
            "endpoint": "https://s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": True,
            "method": "GET",
            "key": "",
            "query": {"list-type": "2", "continuation-token": "1/abc+dEf==", "max-keys": "1000"},
        },
    ),
    (
        "R2 形态：region=auto + 账号域 endpoint",
        {
            "endpoint": "https://0123456789abcdef.r2.cloudflarestorage.com",
            "region": "auto",
            "bucket": "anime",
            "path_style": True,
            "method": "PUT",
            "key": "anime/3/012-某番 第12话 [1080p].mp4",
            "query": {},
            "body": "r2-payload",
        },
    ),
    (
        "virtual-hosted 寻址（桶名进主机名）",
        {
            "endpoint": "https://examplebucket.s3.us-east-1.amazonaws.com",
            "region": "us-east-1",
            "bucket": "examplebucket",
            "path_style": False,
            "method": "GET",
            "key": "anime/1/001-a.mp4",
            "query": {},
        },
    ),
]


def php_sign(spec):
    """跑 PHP 侧取签名材料"""
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as fh:
        json.dump(spec, fh, ensure_ascii=False)
        path = fh.name
    try:
        r = subprocess.run(
            [PHP, "bin/s3_sigtest.php", path],
            cwd=BACKEND,
            capture_output=True,
            text=True,
            timeout=60,
        )
    finally:
        os.unlink(path)

    if r.returncode != 0:
        raise RuntimeError(f"PHP 侧失败 rc={r.returncode}\nSTDOUT:{r.stdout}\nSTDERR:{r.stderr}")
    return json.loads(r.stdout)


def botocore_sign(spec):
    """用 botocore 对同一个请求签名，返回 (Authorization, canonical_request)"""
    endpoint = spec["endpoint"]
    bucket = spec["bucket"]
    region = spec.get("region", "us-east-1")
    key = spec.get("key", "")
    query = spec.get("query") or {}
    body = spec.get("body")

    path_style = spec.get("path_style")
    host = endpoint.split("://", 1)[-1]
    if path_style is None:
        path_style = not host.startswith(bucket + ".")

    prefix = endpoint.rstrip("/")
    if path_style:
        url = f"{prefix}/{bucket}"
        if key:
            url += "/" + "/".join(_quote(s) for s in key.split("/"))
    else:
        url = prefix
        if key:
            url += "/" + "/".join(_quote(s) for s in key.split("/"))

    if query:
        from urllib.parse import quote

        qs = "&".join(
            f"{quote(str(k), safe='-_.~')}={quote(str(v), safe='-_.~')}"
            for k, v in sorted(query.items())
        )
        url += "?" + qs

    req = AWSRequest(method=spec["method"], url=url, data=(body or "").encode("utf-8") if body else b"")
    auth = S3SigV4Auth(Credentials(access_key=AK, secret_key=SK), "s3", region)

    # 固定时间戳，保证 canonical_request 与 string_to_sign 用同一个日期
    fixed = datetime.datetime(2026, 9, 20, 12, 34, 56, tzinfo=datetime.timezone.utc)
    for attr in ("_timestamp", "timestamp"):
        try:
            setattr(auth, attr, fixed)
        except Exception:
            pass

    auth.add_auth(req)
    authz = req.headers["Authorization"]
    canonical = auth.canonical_request(req)
    return authz, canonical


def _quote(seg):
    from urllib.parse import quote

    return quote(seg, safe="-_.~")


def _strip_authorization(canonical: str) -> str:
    """剔掉 botocore 因 add_auth 之后再取 canonical 而多出的 Authorization 头"""
    out = []
    for line in canonical.splitlines():
        low = line.lower()
        if low.startswith("authorization:"):
            continue
        if low.startswith("authorization;"):
            line = line.split(";", 1)[1]
        out.append(line)
    return "\n".join(out)


def _extract_amz_date(canonical: str):
    for line in canonical.splitlines():
        if line.lower().startswith("x-amz-date:"):
            return line.split(":", 1)[1].strip()
    return None


def main() -> int:
    ok = 0
    bad = 0
    for name, spec in CASES:
        spec = dict(spec)
        spec["access_key"] = AK
        spec["secret_key"] = SK

        try:
            authz, canonical = botocore_sign(spec)
        except Exception as e:  # noqa: BLE001
            print(f"[SKIP] {name}  botocore 侧失败：{type(e).__name__}: {e}")
            continue

        # botocore 的 canonical_request() 是 add_auth **之后**调的，request.headers 里
        # 已经多了 Authorization，于是 canonical 里多出一行 `authorization:…`，签名头列表
        # 也变成了 `authorization;host;…`。真实签名时并不包含它（AWS 明确排除 Authorization），
        # 所以这里把它剔掉，才能和 PHP 侧做同等比较。
        canonical = _strip_authorization(canonical)

        # 取 botocore 实际用的 X-Amz-Date，喂给 PHP，避免两侧跨秒不一致
        amz_date = _extract_amz_date(canonical)
        if not amz_date:
            print(f"[SKIP] {name}  无法从 botocore 的 canonical request 里取到 x-amz-date")
            continue
        spec["amz_date"] = amz_date

        try:
            php = php_sign(spec)
        except Exception as e:  # noqa: BLE001
            print(f"[FAIL] {name}\n   PHP 侧异常：{e}")
            bad += 1
            continue

        sig_b = authz.split("Signature=")[1].strip()
        keys_b = authz.split("SignedHeaders=")[1].split(",")[0]
        sig_p = php["signature"]
        keys_p = php["signed_keys"]

        problems = []
        if sig_b != sig_p:
            problems.append(f"signature 不一致\n     botocore={sig_b}\n     php     ={sig_p}")
        if keys_b != keys_p:
            problems.append(f"signed_keys 不一致\n     botocore={keys_b}\n     php     ={keys_p}")
        # 逐字段比对 canonical request，便于定位是哪一步规范化写错了
        if canonical.strip() != php["canonical_request"].strip():
            problems.append(
                "canonical_request 不一致\n"
                "     --- botocore ---\n"
                + "\n".join("     | " + l for l in canonical.splitlines())
                + "\n     --- php ---\n"
                + "\n".join("     | " + l for l in php["canonical_request"].splitlines())
            )

        if problems:
            bad += 1
            print(f"[FAIL] {name}")
            for p in problems:
                print("   " + p)
        else:
            ok += 1
            print(f"[ OK ] {name}")
            print(f"        scope={php['scope']}  sig={sig_p[:16]}…  path={php['path']}")

    print()
    print(f"对账结果：{ok} 通过 / {bad} 失败 / 共 {len(CASES)} 例")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
