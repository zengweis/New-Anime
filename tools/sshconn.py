#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
共用 SSH 连接器。

这台机器（<server-ip>）的 SSH 握手会偶发「banner 之后 EOF」：
TCP 通、banner 正常返回，但密钥交换被打断。多为 sshd 端瞬时状态
（MaxStartups / 连接复用残留），退避重连即可恢复。
认证失败与配置错误不重试——重试只会白等。

**代理隧道兜底**：本机（WorkBuddy 沙箱）的出网策略会变 —— 直连公网有时被收回，
表现为 TCP 全部超时（连 22/443 都不通），而本地代理（Clash 7897 等）仍然可用。
这时用 HTTP CONNECT 经代理由本地转发到目标 22 端口，把裸 socket 交给 paramiko。
所以顺序是「先直连（快、稳），失败再试代理」，两者都不通才报错。
"""
import os
import socket
import sys
import time

import paramiko

HOST = os.environ.get("AN_HOST", "<server-ip>")
PORT = int(os.environ.get("AN_PORT", "22"))
USER = os.environ.get("AN_USER", "root")
PASS = os.environ.get("AN_PASS", "<server-password>")

RETRIES = int(os.environ.get("AN_RETRIES", "5"))
BACKOFF = float(os.environ.get("AN_BACKOFF", "2.0"))

# 本地可用的 HTTP 代理候选（按优先级）。环境变量里的沙箱代理排最后，
# 因为它经常换端口、也可能直接返回 502。
PROXY_CANDIDATES = [
    *[p for p in (os.environ.get("AN_PROXY", ""),) if p],
    "http://127.0.0.1:7897",        # 用户的 Clash
    os.environ.get("https_proxy", ""),
    os.environ.get("http_proxy", ""),
]

# 只对握手阶段的瞬时故障重试
TRANSIENT = (
    paramiko.ssh_exception.SSHException,
    EOFError,
    OSError,
)


def _proxy_socket(proxy_url: str, timeout: float = 15.0) -> socket.socket:
    """经 HTTP 代理用 CONNECT 建立到 HOST:PORT 的裸 TCP 通道。"""
    url = proxy_url.strip()
    if not url:
        raise OSError("空代理地址")
    if "://" in url:
        url = url.split("://", 1)[1]
    url = url.rstrip("/")
    if "@" in url:                       # 带认证的代理
        url = url.rsplit("@", 1)[1]
    host, _, port = url.partition(":")
    ph, pp = host, int(port or 8080)

    s = socket.create_connection((ph, pp), timeout=timeout)
    s.settimeout(timeout)
    req = f"CONNECT {HOST}:{PORT} HTTP/1.1\r\nHost: {HOST}:{PORT}\r\n\r\n"
    s.sendall(req.encode())
    buf = b""
    while b"\r\n\r\n" not in buf:
        chunk = s.recv(4096)
        if not chunk:
            s.close()
            raise OSError("代理提前关闭连接")
        buf += chunk
    if b" 200" not in buf.split(b"\r\n", 1)[0]:
        s.close()
        raise OSError("CONNECT 被拒绝：" + buf.split(b"\r\n", 1)[0].decode("latin1", "replace"))
    s.settimeout(None)
    return s


def _try_connect(sock=None) -> paramiko.SSHClient:
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(
        HOST,
        port=PORT,
        username=USER,
        password=PASS,
        timeout=25,
        banner_timeout=30,
        auth_timeout=30,
        look_for_keys=False,
        allow_agent=False,
        sock=sock,
    )
    return c


def connect() -> paramiko.SSHClient:
    """建立连接：先直连（含瞬时故障退避），直连全失败再走代理隧道。"""
    last: Exception | None = None
    for attempt in range(1, RETRIES + 1):
        try:
            c = _try_connect()
            if attempt > 1:
                sys.stderr.write(f"[ssh] 第 {attempt} 次直连成功\n")
            return c
        except paramiko.ssh_exception.AuthenticationException:
            raise
        except TRANSIENT as e:
            last = e
            if attempt < RETRIES:
                wait = BACKOFF * attempt
                sys.stderr.write(
                    f"[ssh] 直连失败（{type(e).__name__}），{wait:.1f}s 后重试 "
                    f"({attempt}/{RETRIES})\n"
                )
                time.sleep(wait)

    # ---- 直连不通：试代理隧道 ----
    sys.stderr.write("[ssh] 直连不可用，改用本地代理隧道…\n")
    for url in PROXY_CANDIDATES:
        if not url:
            continue
        try:
            sock = _proxy_socket(url)
            c = _try_connect(sock=sock)
            sys.stderr.write(f"[ssh] 已经由 {url} 建立隧道连接\n")
            return c
        except Exception as e:  # noqa: BLE001
            sys.stderr.write(f"[ssh] 代理由 {url} 失败：{type(e).__name__} {e}\n")

    raise SystemExit(f"[ssh] 直连与代理隧道均失败：{last}")
