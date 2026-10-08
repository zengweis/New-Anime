#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
anime-new 开发服务器 SSH 执行器
用法:
    python tools/ssh_run.py 'command'
    python tools/ssh_run.py < script.sh
    python tools/ssh_put.py local remote   (见 ssh_put.py)
"""
import sys
import os
import paramiko

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402

HOST = os.environ.get("AN_HOST", "<server-ip>")
PORT = int(os.environ.get("AN_PORT", "22"))
USER = os.environ.get("AN_USER", "root")
PASS = os.environ.get("AN_PASS", "<server-password>")


def get_client() -> paramiko.SSHClient:
    return connect()


def run(cmd: str, timeout: int = 600) -> int:
    c = get_client()
    try:
        _, out, err = c.exec_command(cmd, timeout=timeout, get_pty=False)
        o = out.read().decode("utf-8", "replace")
        e = err.read().decode("utf-8", "replace")
        rc = out.channel.recv_exit_status()
        if o:
            sys.stdout.write(o)
        if e.strip():
            sys.stdout.write("\n[stderr]\n" + e)
        return rc
    finally:
        c.close()


if __name__ == "__main__":
    if len(sys.argv) > 1:
        cmd = sys.argv[1]
    else:
        cmd = sys.stdin.read()
    if not cmd.strip():
        sys.exit("empty command")
    sys.exit(run(cmd))
