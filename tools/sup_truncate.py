#!/usr/bin/env python3
"""把 PGS .sup 截断到前 N 条显示，用于快速评估 OCR 质量。

PGS(.sup) 是一串定长头 + 变长负载的段：
    'PG'(2) + PTS(4, 90kHz) + DTS(4) + 段类型(1) + 长度(2) + 负载
其中 0x14 = PCS（展示合成段），每出现一次代表一次字幕更新。
截到第 N 个 PCS 之后停止，即可得到一个语法上合法的「前 N 条」片段，
从而用 1~2 分钟判断识别质量，而不必等整轨二三十分钟。

    python3 sup_truncate.py in.sup out.sup 40
"""

import struct
import sys


def main() -> int:
    src, dst, n = sys.argv[1], sys.argv[2], int(sys.argv[3])
    data = open(src, "rb").read()
    out = bytearray()
    i = 0
    pcs = 0
    while i + 13 <= len(data):
        if data[i : i + 2] != b"PG":
            i += 1
            continue
        size = struct.unpack(">H", data[i + 11 : i + 13])[0]
        total = 13 + size
        seg = data[i : i + total]
        if data[i + 10] == 0x14:
            pcs += 1
            if pcs > n:
                break
        out += seg
        i += total
    open(dst, "wb").write(bytes(out))
    print(f"segments kept until display #{pcs}, wrote {len(out)} bytes -> {dst}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
