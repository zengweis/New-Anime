"""acgn.help HTTPS 端到端验证（经 Cloudflare）"""

import json
import urllib.error
import urllib.request

BASE = "https://www.acgn.help"


def api(path, body=None, token=None, method=None):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method or ("POST" if data else "GET"))
    req.add_header("Content-Type", "application/json")
    req.add_header("User-Agent", "Mozilla/5.0")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    try:
        r = urllib.request.urlopen(req, timeout=20)
        return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {"raw": "(非JSON)"}
    except Exception as e:
        return 0, {"err": f"{type(e).__name__}: {str(e)[:100]}"}


print("=== 经 HTTPS + Cloudflare 的端到端验证 ===")
s, d = api("/api/health")
print(f"  /api/health            -> {s} {json.dumps(d, ensure_ascii=False)[:90]}")

s, d = api("/api/auth/login", {"account": "zeng", "password": "<password>"})
print(f"  /api/auth/login        -> {s} code={d.get('code')} msg={d.get('msg')}")
tok = (d.get("data") or {}).get("token")
if tok:
    print(f"  token                  -> {tok[:36]}... ({len(tok)} chars)")

s, d = api("/api/animes?page=1&size=3")
print(f"  /api/animes (列表)     -> {s} code={d.get('code')} total={(d.get('data') or {}).get('total')}")

s, d = api("/api/home")
print(f"  /api/home              -> {s} code={d.get('code')}")

s, d = api("/api/search?kw=test")
print(f"  /api/search            -> {s} code={d.get('code')}")

if tok:
    s, d = api("/api/auth/me", token=tok)
    print(f"  /api/auth/me (带token) -> {s} code={d.get('code')} user={(d.get('data') or {}).get('username')}")
    s, d = api("/api/admin/overview", token=tok)
    print(f"  /api/admin/overview    -> {s} code={d.get('code')}")
    s, d = api("/api/admin/settings", token=tok)
    print(f"  /api/admin/settings    -> {s} code={d.get('code')}")
    s, d = api("/api/admin/feeds", token=tok)
    print(f"  /api/admin/feeds       -> {s} code={d.get('code')}")

print()
print("=== HTTP -> HTTPS 跳转验证 ===")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


op = urllib.request.build_opener(NoRedirect)
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36"
for u in ["http://www.acgn.help/", "http://acgn.help/", "http://www.acgn.help/api/health"]:
    try:
        r = op.open(urllib.request.Request(u, headers={"User-Agent": UA}), timeout=15)
        print(f"  {u:<34} -> {r.status} Location={r.headers.get('Location')}")
    except urllib.error.HTTPError as e:
        print(f"  {u:<34} -> {e.code} Location={e.headers.get('Location')}")

print()
print("=== 静态资源 & SPA 回退 ===")
for p in ["/", "/anime", "/admin", "/assets/nonexistent.js"]:
    try:
        r = urllib.request.urlopen(
            urllib.request.Request(BASE + p, headers={"User-Agent": UA}), timeout=15
        )
        print(f"  {p:<26} -> HTTP {r.status}  {r.headers.get('Content-Type')}")
    except urllib.error.HTTPError as e:
        print(f"  {p:<26} -> HTTP {e.code}")
    except Exception as e:
        print(f"  {p:<26} -> 失败 {type(e).__name__}")

print()
print("=== 安全响应头（HTTPS）===")
r = urllib.request.urlopen(urllib.request.Request(BASE + "/", headers={"User-Agent": UA}), timeout=15)
for h in ["strict-transport-security", "x-content-type-options", "x-frame-options", "referrer-policy"]:
    print(f"  {h:<32} -> {r.headers.get(h) or '(缺失)'}")
