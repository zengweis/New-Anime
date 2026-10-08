"""极简 S3 兼容 mock 服务器 —— 仅用于本地端到端测试对象存储功能。

特点：
  * **用 botocore（AWS 官方参考实现）独立验证每个请求的 SigV4 签名**。
    PHP 侧手写的签名如果有一处规范化不对，这里会直接返回 403，签名不一致
    还会打印出期望值与实际值。这是「自己写的实现被官方实现在验」的真对账，
    比双方都用同一套误解靠谱。
  * 支持上传链路要用到的全部动作：单次 PUT、分片上传（init/part/complete/abort）、
    HEAD、GET（含 Range）、DELETE、ListObjectsV2（含 prefix / continuation-token）。
  * 桶按「公开读」处理：不带 Authorization 的 GET/HEAD 直接放行，
    这样 302 到公开直链之后浏览器真的能取到字节。
  * `GET /_mock/state` 暴露内部状态（对象、未完成分片、验签失败次数、
    abort 次数），供测试脚本断言「没留下孤儿分片」。

启动：
    python3 tools/s3_mock.py --port 9100 --access-key AK --secret-key SK
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import sys
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from botocore.auth import S3SigV4Auth, S3SigV4QueryAuth
from botocore.awsrequest import AWSRequest
from botocore.credentials import Credentials

STATE = {
    "objects": {},       # key -> {"size": int, "etag": str, "mtime": iso}
    "uploads": {},       # uploadId -> {"key": str, "parts": {n: path}}
    "aborted": 0,
    "completed": 0,
    "sig_ok": 0,
    "sig_fail": 0,
    "requests": 0,
    "failures": [],      # 验签失败明细
}

LOCK = threading.Lock()
CFG = {"data_dir": "/tmp/s3mock", "ak": "", "sk": "", "no_verify": False}


def verify_sig(method, raw_path, headers, body, host):
    """用 botocore 重算签名并比对。"""
    authz = headers.get("authorization", "")
    m = re.match(
        r"AWS4-HMAC-SHA256\s+Credential=([^,]+),\s*SignedHeaders=([^,]+),\s*Signature=([0-9a-f]+)",
        authz,
    )
    if not m:
        return False, f"Authorization 头格式不识别: {authz[:120]!r}"
    cred, signed_headers, sig = m.group(1), m.group(2), m.group(3)
    parts = cred.split("/")
    if len(parts) != 5:
        return False, f"Credential 段数不对: {cred!r}"
    ak, _date, region, service, term = parts
    if ak != CFG["ak"]:
        return False, f"AccessKey 不匹配: {ak!r}"

    ts = headers.get("x-amz-date", "")
    if not ts:
        return False, "缺少 x-amz-date"

    url = f"http://{host}{raw_path}"
    req = AWSRequest(method=method, url=url, data=body if body else b"")
    for h in signed_headers.split(";"):
        if h not in headers:
            return False, f"SignedHeaders 列了 {h!r} 但请求里没有"
        req.headers[h] = headers[h]
    # botocore 的 canonical_request / string_to_sign / signature 都读 request.context['timestamp']
    req.context["timestamp"] = ts

    auth = S3SigV4Auth(Credentials(CFG["ak"], CFG["sk"]), service, region)
    try:
        cr = auth.canonical_request(req)
        sts = auth.string_to_sign(req, cr)
        expect = auth.signature(sts, req)
    except Exception as e:  # noqa: BLE001
        return False, f"botocore 计算异常: {type(e).__name__}: {e}"

    if expect != sig:
        return False, (
            f"签名不一致\n--- botocore ---\n{cr}\n--- 期望 {expect}\n--- 实收 {sig}"
        )
    return True, ""


def verify_presigned(method, raw_path, query, headers, host):
    """用 botocore 的 SigV4QueryAuth 重算预签名并比对。

    预签名的签名在 query 里（没有 Authorization 头），所以必须单独走一条验证路径，
    否则「dl=1 走预签名」这条链路等于没被测到。

    注意：**必须把 X-Amz-Signature 从 query 里剔掉再重算**。签名覆盖的 canonical
    query 不包含它自身（否则就是自指）；如果原样带上，算出来的期望值与实收值必然
    不同，会把一个完全正确的预签名误判成失败。
    """
    sig = query.get("X-Amz-Signature")
    cred = query.get("X-Amz-Credential")
    date = query.get("X-Amz-Date")
    if not (sig and cred and date):
        return False, "预签名参数不全（缺 Signature / Credential / Date）"
    parts = cred.split("/")
    if len(parts) != 5:
        return False, f"X-Amz-Credential 段数不对: {cred!r}"
    ak, _d, region, service, _term = parts
    if ak != CFG["ak"]:
        return False, f"预签名 AccessKey 不匹配: {ak!r}"

    # 去掉 X-Amz-Signature 后重新拼 URL，再交给 botocore 重算
    from urllib.parse import urlencode

    rest = [(k, v) for k, v in query.items() if k != "X-Amz-Signature"]
    url = f"http://{host}{raw_path.split('?', 1)[0]}?{urlencode(rest)}"

    req = AWSRequest(method=method, url=url, data=b"")
    req.headers["host"] = host
    req.context["timestamp"] = date

    auth = S3SigV4QueryAuth(Credentials(CFG["ak"], CFG["sk"]), service, region, expires=3600)
    try:
        cr = auth.canonical_request(req)
        sts = auth.string_to_sign(req, cr)
        expect = auth.signature(sts, req)
    except Exception as e:  # noqa: BLE001
        return False, f"botocore 预签名计算异常: {type(e).__name__}: {e}"

    if expect != sig:
        return False, f"预签名不一致\n--- canonical ---\n{cr}\n期望 {expect}\n实收 {sig}"
    return True, ""


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # 静音，测试输出自己管
        pass

    # ---------------------------------------------------------------- 工具
    def _read_body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n) if n > 0 else b""

    def _host(self):
        return self.headers.get("Host") or f"127.0.0.1:{self.server.server_port}"

    def _split(self):
        parsed = urllib.parse.urlsplit(self.path)
        raw_path = parsed.path
        query = dict(urllib.parse.parse_qsl(parsed.query, keep_blank_values=True))
        segs = [urllib.parse.unquote(s) for s in raw_path.lstrip("/").split("/") if s != ""]
        bucket = segs[0] if segs else ""
        key = "/".join(segs[1:]) if len(segs) > 1 else ""
        return raw_path, query, bucket, key

    def _send(self, code, body=b"", headers=None, content_type=None):
        if isinstance(body, str):
            body = body.encode("utf-8")
        headers = dict(headers or {})
        self.send_response(code)
        if content_type:
            self.send_header("Content-Type", content_type)
        for k, v in headers.items():
            self.send_header(k, v)
        self.send_header("x-amz-request-id", "mock")
        # HEAD 需要显式声明对象的真实长度（body 为空），此时不能重复发 Content-Length
        if not any(k.lower() == "content-length" for k in headers):
            self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD" and body:
            self.wfile.write(body)

    def _err(self, code, s3code, msg):
        xml = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            f"<Error><Code>{s3code}</Code><Message>{msg}</Message>"
            f"<RequestId>mock</RequestId></Error>"
        )
        self._send(code, xml, content_type="application/xml")

    def _path_for(self, key):
        return os.path.join(CFG["data_dir"], key)

    # ---------------------------------------------------------------- 入口
    def _handle(self):
        STATE["requests"] += 1
        raw_path, query, bucket, key = self._split()

        # 测试专用内省接口
        if raw_path == "/_mock/state":
            return self._send(
                200,
                json.dumps(
                    {
                        "objects": sorted(STATE["objects"].keys()),
                        "object_sizes": {k: v["size"] for k, v in STATE["objects"].items()},
                        "pending_uploads": sorted(STATE["uploads"].keys()),
                        "aborted": STATE["aborted"],
                        "completed": STATE["completed"],
                        "sig_ok": STATE["sig_ok"],
                        "sig_fail": STATE["sig_fail"],
                        "requests": STATE["requests"],
                        "failures": STATE["failures"][-5:],
                    },
                    ensure_ascii=False,
                ),
                content_type="application/json",
            )

        body = self._read_body()

        # 三种请求：
        #   1) 带 Authorization → 用 botocore 重算头签名
        #   2) 带 X-Amz-Signature → 预签名，用 botocore 的 SigV4QueryAuth 重算
        #   3) 什么都不带的无认证 GET/HEAD → 当作公开读（模拟公开桶）
        has_authz = bool(self.headers.get("Authorization"))
        presigned = "X-Amz-Signature" in query
        anonymous_read = (
            self.command in ("GET", "HEAD", "OPTIONS") and not has_authz and not presigned
        )

        if not anonymous_read and not CFG["no_verify"]:
            if presigned and not has_authz:
                ok, why = verify_presigned(self.command, self.path, query, self.headers, self._host())
            else:
                ok, why = verify_sig(self.command, self.path, self.headers, body, self._host())
            if not ok:
                with LOCK:
                    STATE["sig_fail"] += 1
                    STATE["failures"].append({"method": self.command, "path": self.path, "why": why[:900]})
                return self._err(403, "SignatureDoesNotMatch", "mock 验签失败")
            STATE["sig_ok"] += 1
        elif presigned:
            # 预签名请求走通验签路径，计入统计
            STATE["sig_ok"] += 1

        try:
            self._dispatch(bucket, key, query, body, anonymous_read)
        except Exception as e:  # noqa: BLE001
            with LOCK:
                STATE["failures"].append({"method": self.command, "path": self.path, "why": f"内部异常 {e!r}"})
            self._err(500, "InternalError", repr(e))

    def _dispatch(self, bucket, key, query, body, anonymous_read):
        method = self.command

        # ---- 分片：初始化 ----
        if method == "POST" and "uploads" in query:
            uid = hashlib.md5(f"{key}:{os.urandom(8).hex()}".encode()).hexdigest()
            with LOCK:
                STATE["uploads"][uid] = {"key": key, "parts": {}}
            xml = (
                '<?xml version="1.0" encoding="UTF-8"?>'
                '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                f"<Bucket>{bucket}</Bucket><Key>{key}</Key><UploadId>{uid}</UploadId>"
                "</InitiateMultipartUploadResult>"
            )
            return self._send(200, xml, content_type="application/xml")

        # ---- 分片：上传单片 ----
        if method == "PUT" and "partNumber" in query and "uploadId" in query:
            uid = query["uploadId"]
            up = STATE["uploads"].get(uid)
            if up is None:
                return self._err(404, "NoSuchUpload", "unknown uploadId")
            n = int(query["partNumber"])
            d = os.path.join(CFG["data_dir"], ".uploads", uid)
            os.makedirs(d, exist_ok=True)
            p = os.path.join(d, f"{n:05d}.part")
            with open(p, "wb") as fh:
                fh.write(body)
            with LOCK:
                up["parts"][n] = p
            etag = '"' + hashlib.md5(body).hexdigest() + '"'
            return self._send(200, b"", {"ETag": etag, "x-amz-request-id": "mock"})

        # ---- 分片：合并 ----
        if method == "POST" and "uploadId" in query:
            uid = query["uploadId"]
            up = STATE["uploads"].get(uid)
            if up is None:
                return self._err(404, "NoSuchUpload", "unknown uploadId")
            nums = sorted(up["parts"].keys())
            if not nums:
                return self._err(400, "InvalidRequest", "no parts")
            dst = self._path_for(up["key"])
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            h = hashlib.md5()
            size = 0
            with open(dst, "wb") as out:
                for n in nums:
                    with open(up["parts"][n], "rb") as fh:
                        while True:
                            chunk = fh.read(1024 * 1024)
                            if not chunk:
                                break
                            out.write(chunk)
                            h.update(chunk)
                            size += len(chunk)
            for n in nums:
                os.unlink(up["parts"][n])
            os.rmdir(os.path.dirname(up["parts"][nums[0]]))
            etag = '"' + h.hexdigest() + '-' + str(len(nums)) + '"'
            with LOCK:
                STATE["uploads"].pop(uid, None)
                STATE["completed"] += 1
                STATE["objects"][up["key"]] = {
                    "size": size,
                    "etag": etag,
                    "mtime": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z"),
                }
            xml = (
                '<?xml version="1.0" encoding="UTF-8"?>'
                '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                f"<Location>http://{self._host()}/{bucket}/{up['key']}</Location>"
                f"<Bucket>{bucket}</Bucket><Key>{up['key']}</Key><ETag>{etag}</ETag>"
                "</CompleteMultipartUploadResult>"
            )
            return self._send(200, xml, content_type="application/xml")

        # ---- 分片：中止 ----
        if method == "DELETE" and "uploadId" in query:
            uid = query["uploadId"]
            up = STATE["uploads"].pop(uid, None)
            if up is not None:
                for n, p in list(up["parts"].items()):
                    if os.path.exists(p):
                        os.unlink(p)
                d = os.path.join(CFG["data_dir"], ".uploads", uid)
                if os.path.isdir(d):
                    try:
                        os.rmdir(d)
                    except OSError:
                        pass
                with LOCK:
                    STATE["aborted"] += 1
            return self._send(204)

        # ---- 单次 PUT ----
        if method == "PUT" and key:
            p = self._path_for(key)
            os.makedirs(os.path.dirname(p), exist_ok=True)
            with open(p, "wb") as fh:
                fh.write(body)
            etag = '"' + hashlib.md5(body).hexdigest() + '"'
            with LOCK:
                STATE["objects"][key] = {
                    "size": len(body),
                    "etag": etag,
                    "mtime": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z"),
                }
            return self._send(200, b"", {"ETag": etag})

        # ---- DELETE ----
        if method == "DELETE" and key:
            with LOCK:
                existed = STATE["objects"].pop(key, None)
            p = self._path_for(key)
            if os.path.exists(p):
                os.unlink(p)
            return self._send(204 if existed else 204)

        # ---- HEAD ----
        if method == "HEAD" and key:
            meta = STATE["objects"].get(key)
            if meta is None:
                return self._send(404)
            return self._send(
                200,
                b"",
                {
                    "Content-Length": str(meta["size"]),
                    "ETag": meta["etag"],
                    "Last-Modified": meta["mtime"],
                    "Content-Type": "application/octet-stream",
                    "Accept-Ranges": "bytes",
                },
                content_type="application/octet-stream",
            )

        # ---- GET 对象（支持 Range）----
        if method == "GET" and key:
            meta = STATE["objects"].get(key)
            p = self._path_for(key)
            if meta is None or not os.path.exists(p):
                return self._err(404, "NoSuchKey", "not found")
            size = meta["size"]
            rng = self.headers.get("Range")
            start, end = 0, size - 1
            code = 200
            extra = {}
            if rng and (m := re.match(r"bytes=(\d*)-(\d*)", rng)):
                if m.group(1) == "" and m.group(2) != "":
                    start, end = max(0, size - int(m.group(2))), size - 1
                else:
                    start = int(m.group(1))
                    end = int(m.group(2)) if m.group(2) != "" else size - 1
                end = min(end, size - 1)
                if start > end or start >= size:
                    return self._send(416, b"", {"Content-Range": f"bytes */{size}"})
                code = 206
                extra["Content-Range"] = f"bytes {start}-{end}/{size}"
            with open(p, "rb") as fh:
                fh.seek(start)
                data = fh.read(end - start + 1)
            extra.update(
                {
                    "ETag": meta["etag"],
                    "Accept-Ranges": "bytes",
                    "Cache-Control": "public, max-age=60",
                }
            )
            return self._send(code, data, extra, content_type="application/octet-stream")

        # ---- ListObjectsV2（桶级）----
        if method == "GET" and not key:
            q = query
            prefix = q.get("prefix", "")
            max_keys = int(q.get("max-keys", "1000") or 1000)
            token = q.get("continuation-token")
            keys = sorted(k for k in STATE["objects"] if k.startswith(prefix))
            start = int(token) if token not in (None, "") else 0
            page = keys[start : start + max_keys]
            truncated = start + max_keys < len(keys)
            nxt = str(start + max_keys) if truncated else ""

            parts = [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">',
                f"<Name>{bucket}</Name>",
                f"<Prefix>{prefix}</Prefix>",
                f"<KeyCount>{len(page)}</KeyCount>",
                f"<MaxKeys>{max_keys}</MaxKeys>",
                f"<IsTruncated>{'true' if truncated else 'false'}</IsTruncated>",
            ]
            if truncated:
                parts.append(f"<NextContinuationToken>{nxt}</NextContinuationToken>")
            for k in page:
                meta = STATE["objects"][k]
                parts.append(
                    "<Contents>"
                    f"<Key>{k}</Key>"
                    f"<LastModified>{meta['mtime']}</LastModified>"
                    f"<ETag>{meta['etag']}</ETag>"
                    f"<Size>{meta['size']}</Size>"
                    "<StorageClass>STANDARD</StorageClass>"
                    "</Contents>"
                )
            parts.append("</ListBucketResult>")
            return self._send(200, "".join(parts), content_type="application/xml")

        return self._err(400, "InvalidRequest", f"mock 不处理 {method} {self.path}")

    def do_GET(self):
        self._handle()

    def do_HEAD(self):
        self._handle()

    def do_PUT(self):
        self._handle()

    def do_POST(self):
        self._handle()

    def do_DELETE(self):
        self._handle()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=9100)
    ap.add_argument("--access-key", required=True)
    ap.add_argument("--secret-key", required=True)
    ap.add_argument("--data-dir", default="/tmp/s3mock")
    ap.add_argument("--no-verify", action="store_true")
    ap.add_argument(
        "--ready-file",
        default="/tmp/s3mock_ready",
        help="启动后写入该文件，供测试脚本等待就绪",
    )
    a = ap.parse_args()

    CFG["data_dir"] = a.data_dir
    CFG["ak"] = a.access_key
    CFG["sk"] = a.secret_key
    CFG["no_verify"] = a.no_verify
    os.makedirs(os.path.join(a.data_dir, ".uploads"), exist_ok=True)

    srv = ThreadingHTTPServer(("127.0.0.1", a.port), Handler)
    with open(a.ready_file, "w", encoding="utf-8") as fh:
        fh.write(f"{a.port}\n")
    print(f"mock S3 已启动 http://127.0.0.1:{a.port}  data={a.data_dir}", flush=True)
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
