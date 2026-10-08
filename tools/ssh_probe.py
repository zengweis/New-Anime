"""在服务器上跑一组只读检查，把结果落成文件再取回本地解析（避免引号地狱）。

用法： python tools/ssh_probe.py <任务名>
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402

TOKEN_CMD = (
    "curl -s -m 8 -k -X POST https://127.0.0.1/api/auth/login "
    "-H 'Content-Type: application/json' "
    "-d '{\"account\":\"zeng\",\"password\":\"<password>\"}' "
    "| python3 -c 'import sys,json;print(json.load(sys.stdin)[\"data\"][\"token\"])'"
)


def sh(c, cmd, t=200):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()


def main() -> int:
    c = connect()
    try:
        tok = sh(c, TOKEN_CMD)
        if not tok:
            print("登录失败")
            return 1

        # 三个接口的结果分别落文件
        sh(
            c,
            "curl -sk -m 10 -H 'Authorization: Bearer %s' "
            "https://127.0.0.1/api/admin/animes/19/episodes -o /tmp/probe_admin.json" % tok,
        )
        sh(
            c,
            "curl -sk -m 10 -H 'Authorization: Bearer %s' "
            "https://127.0.0.1/api/episodes/49 -o /tmp/probe_play.json" % tok,
        )
        sh(
            c,
            "curl -sk -m 10 -H 'Authorization: Bearer %s' "
            "https://127.0.0.1/api/episodes/80 -o /tmp/probe_play80.json" % tok,
        )

        sftp = c.open_sftp()
        for name, path in [
            ("后台集数接口 /admin/animes/19/episodes", "/tmp/probe_admin.json"),
            ("播放页接口 /episodes/49", "/tmp/probe_play.json"),
            ("播放页接口 /episodes/80", "/tmp/probe_play80.json"),
        ]:
            try:
                raw = sftp.open(path).read().decode("utf-8", "replace")
            except Exception as ex:  # noqa: BLE001
                print(f"{name}: 读取失败 {ex}")
                continue
            print(f"=== {name} ===")
            try:
                d = json.loads(raw)
            except Exception:
                print("  非 JSON:", raw[:300])
                continue
            print("  code =", d.get("code"), "|", d.get("msg"))
            data = d.get("data") or {}
            if "list" in data:
                for r in data["list"]:
                    print(
                        f"    #{r['id']} 第{r['ep_no']}集 remuxed={r['remuxed']} "
                        f"storage={r['storage']} s3_status={r['s3_status']} size={r['file_size']}"
                    )
            elif "episode" in data:
                ep = data["episode"]
                pb = ep.get("playback") or {}
                print(
                    f"    ext={ep.get('ext')} playable={ep.get('playable')} remuxed={ep.get('remuxed')} "
                    f"remote={ep.get('remote')} storage={ep.get('storage')}"
                )
                print(f"    playback.mode={pb.get('mode')} note={pb.get('note')}")
                print(f"    subtitles={len(ep.get('subtitles') or [])} state={ep.get('subtitle_state')}")
            print()
        sftp.close()
    finally:
        c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
