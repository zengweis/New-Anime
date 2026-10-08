"""确认「字幕」设置页签在前后端都到位。

    AN_HOST=... AN_PASS=... python tools/check_subs_ui.py
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402


def main() -> int:
    c = connect()

    def sh(cmd, t=200):
        _, o, e = c.exec_command(cmd, timeout=t)
        return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()

    print("=== 前端产物里是否含「字幕」页签 ===")
    for label, pat in [
        ("subs_ocr_bitmap", "subs_ocr_bitmap"),
        ("位图字幕自动识别", "位图字幕自动识别"),
        ("sup 保留策略选项", "保留全部语言轨"),
    ]:
        n = sh(f"grep -o '{pat}' /opt/anime-new/frontend/dist/assets/*.js 2>/dev/null | wc -l")
        print(f"  {label:<18} 出现 {n} 次")

    print()
    print("=== 设置接口 ===")
    tok = sh(
        "curl -s -m 8 -k -X POST https://127.0.0.1/api/auth/login "
        "-H 'Content-Type: application/json' "
        "-d '{\"account\":\"zeng\",\"password\":\"<password>\"}' "
        "| python3 -c 'import sys,json;print(json.load(sys.stdin)[\"data\"][\"token\"])'"
    )
    sh(f"curl -sk -m 10 -H 'Authorization: Bearer {tok}' https://127.0.0.1/api/admin/settings -o /tmp/st.json")
    sftp = c.open_sftp()
    try:
        d = json.loads(sftp.open("/tmp/st.json").read().decode("utf-8", "replace"))["data"]
        print("  groups =", d["groups"])
        picked = {k: v for k, v in d["settings"].items() if k.startswith("subs") or k == "ffmpeg_path"}
        print("  subs_* =", json.dumps(picked, ensure_ascii=False))
    except Exception as ex:  # noqa: BLE001
        print("  解析失败:", ex)
    sftp.close()

    print()
    print("=== OCR 环境 ===")
    print(sh("cd /opt/anime-new/backend && php bin/ocr_subs.php env 2>&1 | tail -3"))
    c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
