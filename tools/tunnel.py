#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
把远端服务映射到本机回环端口，便于本地浏览器直连（绕开代理/拦截）。

    python tools/tunnel.py 18080 <server-ip> 80

按 Ctrl+C 结束。仅监听 127.0.0.1，不对外暴露。
"""
import os
import socket
import sys
import threading

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402


def open_transport():
    """建连接并返回 (client, transport)。带 30s 心跳，防止被中间设备静默掐断。"""
    client = connect()
    transport = client.get_transport()
    assert transport is not None
    transport.set_keepalive(30)
    return client, transport


def forward(local_port: int, remote_host: str, remote_port: int) -> None:
    client, transport = open_transport()

    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", local_port))
    srv.listen(64)
    print("隧道就绪： http://127.0.0.1:%d/  ->  %s:%d" % (local_port, remote_host, remote_port), flush=True)

    def pump(src, dst) -> None:
        try:
            while True:
                data = src.recv(32768)
                if not data:
                    break
                dst.sendall(data)
        except OSError:
            pass
        finally:
            for s in (src, dst):
                try:
                    s.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
                try:
                    s.close()
                except OSError:
                    pass

    try:
        while True:
            conn, addr = srv.accept()

            # 长跑隧道会因 SSH 层断开而失效；这里按需重建，而不是整个进程退出
            if not transport.is_active():
                print("SSH 连接已断，重建中…", flush=True)
                try:
                    client.close()
                except Exception:
                    pass
                try:
                    client, transport = open_transport()
                    print("已重建，隧道继续可用", flush=True)
                except Exception as e:
                    print("重建失败：%s" % e, flush=True)
                    try:
                        conn.close()
                    except OSError:
                        pass
                    continue

            try:
                chan = transport.open_channel(
                    "direct-tcpip", (remote_host, remote_port), addr
                )
            except Exception as e:
                # 单条连接开不出来不该拖垮整个隧道
                print("打开通道失败：%s" % e, flush=True)
                try:
                    conn.close()
                except OSError:
                    pass
                continue

            threading.Thread(target=pump, args=(conn, chan), daemon=True).start()
            threading.Thread(target=pump, args=(chan, conn), daemon=True).start()
    except KeyboardInterrupt:
        print("隧道关闭")
    finally:
        srv.close()
        client.close()


if __name__ == "__main__":
    lp = int(sys.argv[1]) if len(sys.argv) > 1 else 18080
    rh = sys.argv[2] if len(sys.argv) > 2 else "127.0.0.1"
    rp = int(sys.argv[3]) if len(sys.argv) > 3 else 80
    forward(lp, rh, rp)
