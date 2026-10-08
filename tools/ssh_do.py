"""在服务器上跑一组命令（避免本地 shell 转义地狱）。

用法： python tools/ssh_do.py <任务名>
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402


def sh(c, cmd, t=180):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()


def backend(*cmds):
    return [f"cd /opt/anime-new/backend && {c}" for c in cmds]


LOGIN = (
    "curl -s -m 8 -k -X POST https://127.0.0.1/api/auth/login "
    "-H 'Content-Type: application/json' "
    "-d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
)


def token(c):
    import json

    out = sh(c, LOGIN)
    try:
        return json.loads(out)["data"]["token"]
    except Exception:
        raise SystemExit("登录失败：" + out[:300])


def pretty(c, cmd):
    return sh(c, cmd + " | python3 -m json.tool 2>&1 | head -60")


TASKS = {}


def task(name):
    def deco(fn):
        TASKS[name] = fn
        return fn

    return deco


@task("s3-smoke")
def s3_smoke(c):
    print("=== s3_upload.php stat ===")
    print(sh(c, "cd /opt/anime-new/backend && php bin/s3_upload.php stat"))
    print()
    print("=== s3_upload.php test（未配置时的报错）===")
    print(sh(c, "cd /opt/anime-new/backend && php bin/s3_upload.php test"))
    print()
    t = token(c)
    print("=== 设置接口的 s3 分组 ===")
    print(
        sh(
            c,
            "curl -sk -m 8 https://127.0.0.1/api/admin/settings "
            f"-H 'Authorization: Bearer {t}' | python3 -c \"import sys,json;d=json.load(sys.stdin)['data'];"
            "print('groups:', d['groups']);"
            "print({k:v for k,v in d['settings'].items() if k.startswith('s3')})\"",
        )
    )
    print()
    print("=== s3-stats ===")
    print(pretty(c, f"curl -sk -m 8 https://127.0.0.1/api/admin/settings/s3-stats -H 'Authorization: Bearer {t}'"))
    print()
    print("=== s3-test（未配置应报业务错误，不是 500）===")
    print(
        pretty(
            c,
            "curl -sk -m 8 -X POST https://127.0.0.1/api/admin/settings/s3-test "
            f"-H 'Authorization: Bearer {t}' -H 'Content-Type: application/json' -d '{{}}'",
        )
    )
    print()
    print("=== s3/objects（未启用应报错）===")
    print(pretty(c, f"curl -sk -m 8 https://127.0.0.1/api/admin/s3/objects -H 'Authorization: Bearer {t}'"))


@task("migrate-status")
def migrate_status(c):
    print(sh(c, "cd /opt/anime-new/backend && php bin/migrate.php --list"))


@task("php-lint")
def php_lint(c):
    files = [
        "src/Core/S3.php",
        "src/Service/S3Uploader.php",
        "src/Controller/AdminController.php",
        "src/Controller/AdminSettingController.php",
        "src/Controller/AnimeController.php",
        "public/stream.php",
        "public/index.php",
        "bin/daemon.php",
        "bin/migrate.php",
        "bin/s3_upload.php",
    ]
    for f in files:
        r = sh(c, f"cd /opt/anime-new/backend && php -l {f} 2>&1 | tail -1")
        print(f"  {f:<46} {r}")


def main() -> int:
    if len(sys.argv) < 2 or sys.argv[1] not in TASKS:
        print("可用任务：" + ", ".join(TASKS))
        return 1
    c = connect()
    try:
        TASKS[sys.argv[1]](c)
    finally:
        c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
