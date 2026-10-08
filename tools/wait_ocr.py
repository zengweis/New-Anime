"""等 OCR 跑完 → 验收 → 清理。用于位图字幕链路的最终确认。

    AN_HOST=... AN_PASS=... python tools/wait_ocr.py [最大等待秒数]
"""

import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402


def main() -> int:
    limit = int(sys.argv[1]) if len(sys.argv) > 1 else 1800
    c = connect()

    def sh(cmd, t=300):
        _, o, e = c.exec_command(cmd, timeout=t)
        return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()

    print("=== 等待 OCR 完成（daemon 每 60 秒回收一次）===")
    t0 = time.time()
    ok = False
    while time.time() - t0 < limit:
        st = sh("cd /opt/anime-new/backend && php bin/ocr_subs.php status 2>&1")
        done = "已完成 : 0" not in st
        elapsed = int(time.time() - t0)
        line = [x for x in st.splitlines() if x.strip()][:4]
        print(f"[{elapsed:>4}s] " + " | ".join(line))
        if done:
            ok = True
            break
        # 进程没了但也没产物 → 卡住了
        if not sh("pgrep -c -f pgsrip || echo 0").strip().isdigit():
            pass
        time.sleep(30)

    print()
    if not ok:
        print("!! 超时仍未完成，看日志：")
        print(sh("tail -6 /data/anime-subs/80/*.ocr.log 2>/dev/null"))
        c.close()
        return 1

    print("=== 1. 最终状态 ===")
    print(sh("cd /opt/anime-new/backend && php bin/ocr_subs.php status 2>&1"))
    print()
    print("=== 2. 识别出的 VTT（清洗后）前 30 行 ===")
    print(sh("cat /data/anime-subs/80/s3.vtt 2>/dev/null | head -30"))
    print()
    print("=== 3. 字数统计（判断是不是真有内容）===")
    print(sh("grep -c ' --> ' /data/anime-subs/80/s3.vtt 2>/dev/null; wc -c < /data/anime-subs/80/s3.vtt 2>/dev/null"))
    print()
    print("=== 4. 数据库字幕行 ===")
    print(sh("mysql anime_new -e \"SELECT id,track_index,codec,language,label,file,cues,bytes FROM episode_subtitle WHERE episode_id=80\""))
    print()
    print("=== 5. 播放接口 ===")
    tok = sh(
        "curl -s -m 8 -k -X POST https://127.0.0.1/api/auth/login "
        "-H 'Content-Type: application/json' "
        "-d '{\"account\":\"zeng\",\"password\":\"<password>\"}' "
        "| python3 -c 'import sys,json;print(json.load(sys.stdin)[\"data\"][\"token\"])'"
    )
    sh(f"curl -sk -m 10 -H 'Authorization: Bearer {tok}' https://127.0.0.1/api/episodes/80 -o /tmp/e80.json")
    sftp = c.open_sftp()
    try:
        import json

        d = json.loads(sftp.open("/tmp/e80.json").read().decode("utf-8", "replace"))["data"]["episode"]
        print("  subtitles =", json.dumps(d.get("subtitles"), ensure_ascii=False))
        print("  state     =", json.dumps(d.get("subtitle_state"), ensure_ascii=False))
    except Exception as ex:  # noqa: BLE001
        print("  解析失败:", ex)
    sftp.close()
    print()
    print("  /subtitle/80/0.vtt ->", sh(f"curl -sk -m 10 -o /dev/null -w '%{{http_code}} %{{size_download}}B' 'https://127.0.0.1/subtitle/80/0.vtt?token={tok}'"))
    print()
    print("=== 6. 清理：把测试用的本地源文件删掉（它已在 R2），释放磁盘 ===")
    print(sh("cd /opt/anime-new/backend && php bin/s3_upload.php purge --all 2>&1 | tail -6"))
    print()
    print("=== 7. 清理 /tmp 探测残留 ===")
    print(sh("rm -rf /tmp/ocr /tmp/chunk.mp4 /tmp/cnt.py /tmp/tess_* /tmp/probe_*.json 2>/dev/null; cd /opt/anime-new/backend && rm -rf /data/downloads/anime/__e2e_* 2>/dev/null; echo cleaned"))
    print()
    print("=== 8. 清理后的播放判定（应回到远端 MP4 直通）===")
    sh(f"curl -sk -m 10 -H 'Authorization: Bearer {tok}' https://127.0.0.1/api/episodes/80 -o /tmp/e80b.json")
    sftp = c.open_sftp()
    try:
        import json

        d = json.loads(sftp.open("/tmp/e80b.json").read().decode("utf-8", "replace"))["data"]["episode"]
        print("  ext =", d.get("ext"), "| playable =", d.get("playable"), "| storage =", d.get("storage"), "| mode =", (d.get("playback") or {}).get("mode"))
        print("  subtitles =", len(d.get("subtitles") or []), "轨")
    except Exception as ex:  # noqa: BLE001
        print("  解析失败:", ex)
    sftp.close()
    print()
    print("=== 9. 磁盘 ===")
    print(sh("df -h / | tail -1; du -sh /data/anime-subs 2>/dev/null"))

    c.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
