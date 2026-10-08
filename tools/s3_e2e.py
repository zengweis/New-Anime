"""跑对象存储端到端验收：拉起 mock S3 → 跑 PHP 验收脚本 → 校验 mock 侧状态。

在服务器上运行：

    /tmp/s3t/bin/python /opt/anime-new/tools/s3_e2e.py
    /tmp/s3t/bin/python /opt/anime-new/tools/s3_e2e.py --app https://127.0.0.1
"""

import argparse
import json
import os
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
BACKEND = os.environ.get("AN_BACKEND", "/opt/anime-new/backend")
MOCK = os.path.join(HERE, "s3_mock.py")
AK = "AKIAE2EMOCKACCESSKEY"
SK = "e2e-mock-secret-key-0123456789abcdef"


def free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def wait_ready(path: str, timeout: float = 20.0) -> bool:
    t0 = time.time()
    while time.time() - t0 < timeout:
        if os.path.exists(path):
            return True
        time.sleep(0.2)
    return False


def mock_state(url: str) -> dict:
    try:
        with urllib.request.urlopen(url + "/_mock/state", timeout=10) as r:
            return json.loads(r.read().decode())
    except Exception as e:  # noqa: BLE001
        return {"_error": f"{type(e).__name__}: {e}"}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--app", default="https://127.0.0.1", help="应用基址（给 PHP 脚本用）")
    ap.add_argument("--keep", action="store_true", help="保留 mock 数据目录便于排查")
    a = ap.parse_args()

    port = free_port()
    mock_url = f"http://127.0.0.1:{port}"
    data_dir = f"/tmp/s3mock-e2e-{port}"
    ready = f"/tmp/s3mock-e2e-ready-{port}"
    for p in (ready,):
        if os.path.exists(p):
            os.unlink(p)

    print(f"启动 mock S3：{mock_url}  数据目录 {data_dir}")
    print(f"（mock 用 botocore 独立验证每个请求的 SigV4 签名）\n")

    log = open(f"/tmp/s3mock-e2e-{port}.log", "wb")
    proc = subprocess.Popen(
        [
            sys.executable,
            MOCK,
            "--port", str(port),
            "--access-key", AK,
            "--secret-key", SK,
            "--data-dir", data_dir,
            "--ready-file", ready,
        ],
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    rc = 1
    try:
        if not wait_ready(ready):
            print("mock 启动失败：")
            with open(f"/tmp/s3mock-e2e-{port}.log", encoding="utf-8", errors="replace") as fh:
                print(fh.read()[-2000:])
            return 1

        print("=" * 60)
        r = subprocess.run(
            ["php", "bin/e2e_s3.php", mock_url, a.app],
            cwd=BACKEND,
            capture_output=True,
            text=True,
            timeout=900,
        )
        print(r.stdout)
        if r.stderr.strip():
            print("--- PHP stderr ---")
            print(r.stderr[-3000:])
        rc = r.returncode

        # ---- 独立复核 mock 侧状态（不依赖 PHP 自己的断言）----
        st = mock_state(mock_url)
        print("=" * 60)
        print("mock 侧独立复核")
        print(f"  总请求        : {st.get('requests')}")
        print(f"  验签通过      : {st.get('sig_ok')}")
        print(f"  验签失败      : {st.get('sig_fail')}")
        print(f"  分片上传完成  : {st.get('completed')}")
        print(f"  分片中止      : {st.get('aborted')}")
        print(f"  残留未完成分片: {len(st.get('pending_uploads') or [])}")
        print(f"  遗留对象      : {st.get('objects')}")

        problems = []
        if st.get("sig_fail"):
            problems.append(f"有 {st['sig_fail']} 个请求验签失败")
        if st.get("pending_uploads"):
            problems.append(f"有未完成的分片上传残留：{st['pending_uploads']}")
        if st.get("objects"):
            problems.append(f"测试对象未清理干净：{st['objects']}")
        if (st.get("completed") or 0) < 1:
            problems.append("未观察到分片合并，分片链路可能没被触发")
        for f in (st.get("failures") or [])[:3]:
            print("\n--- mock 记录的失败明细 ---")
            print(json.dumps(f, ensure_ascii=False, indent=2)[:2000])

        if problems:
            print()
            for p in problems:
                print(f"  !! {p}")
            rc = 1
        else:
            print("\n  [OK] mock 侧复核通过：验签零失败、无孤儿分片、测试对象已清理")
    finally:
        proc.send_signal(signal.SIGTERM)
        try:
            proc.wait(timeout=8)
        except subprocess.TimeoutExpired:
            proc.kill()
        log.close()
        if os.path.exists(ready):
            os.unlink(ready)
        if not a.keep:
            shutil.rmtree(data_dir, ignore_errors=True)
        else:
            print(f"\n（保留 mock 数据：{data_dir}）")

    print()
    print(f"最终退出码：{rc}")
    return rc


if __name__ == "__main__":
    sys.exit(main())
