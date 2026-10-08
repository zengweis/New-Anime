"""第 80 集位图字幕（PGS→OCR）链路的验收探针。

在服务器上取三类证据：
  1. 播放接口给前端的字幕清单（subtitles / subtitle_state）
  2. 字幕文件分发是否 200 且内容为清洗后的 VTT
  3. 真实 OCR 的启动情况（进程 / 产物 / 提示文案）

    AN_HOST=... AN_PASS=... python tools/probe80.py
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


def sh(c, cmd, t=300):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()


def main() -> int:
    c = connect()
    try:
        tok = sh(c, TOKEN_CMD)
        if not tok:
            print("登录失败")
            return 1

        print("=== 1. 播放接口的字幕清单 ===")
        sh(c, f"curl -sk -m 10 -H 'Authorization: Bearer {tok}' https://127.0.0.1/api/episodes/80 -o /tmp/e80.json")
        sftp = c.open_sftp()
        try:
            d = json.loads(sftp.open("/tmp/e80.json").read().decode("utf-8", "replace"))["data"]["episode"]
        except Exception as ex:  # noqa: BLE001
            print("  读取失败:", ex)
            d = None
        if d:
            print("  ext =", d.get("ext"), "| playable =", d.get("playable"), "| storage =", d.get("storage"))
            print("  subtitles =", json.dumps(d.get("subtitles"), ensure_ascii=False))
            print("  subtitle_state =", json.dumps(d.get("subtitle_state"), ensure_ascii=False))
        sftp.close()

        print()
        print("=== 2. 字幕分发（/subtitle/80/0.vtt）===")
        print(" ", sh(c, f"curl -sk -m 10 -o /dev/null -D - 'https://127.0.0.1/subtitle/80/0.vtt?token={tok}' | grep -iE 'HTTP/|content-type|content-length'"))
        body = sh(c, f"curl -sk -m 10 'https://127.0.0.1/subtitle/80/0.vtt?token={tok}'")
        print("  正文前 8 行:")
        for line in body.splitlines()[:8]:
            print("   ", line)

        print()
        print("=== 3. 真实 OCR 状态 ===")
        print("  进程:", sh(c, "pgrep -af pgsrip | head -2") or "(无)")
        print("  目录:")
        print(" ", sh(c, "ls -la /data/anime-subs/80/ 2>&1").replace("\n", "\n  "))
        print("  提示文案:", sh(c, "mysql anime_new -N -B -e \"SELECT IFNULL(sub_extract_err,'(空)') FROM episode WHERE id=80\""))
        print("  字幕行:", sh(c, "mysql anime_new -N -B -e \"SELECT COUNT(*) FROM episode_subtitle WHERE episode_id=80\""))
        print()
        print("  OCR 日志尾:")
        print(" ", sh(c, "tail -3 /data/anime-subs/80/*.ocr.log 2>/dev/null || echo '(暂无)'"))
        print("  daemon 日志尾:")
        print(" ", sh(c, "grep -E 'OCR|位图' /opt/anime-new/backend/storage/logs/daemon.log | tail -4 || echo '(暂无)'"))
    finally:
        c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
