#!/usr/bin/env python3
"""诊断 PGS 结构：逐 display set dump 段类型与对象数，定位提取器为什么大量判空。

    python3 pgs_diag.py <file.sup> [前 N 个 display set]
"""

import collections
import os
import struct
import sys

for cand in ("/opt/anime-new/venv-ocr/lib/python3.12/site-packages",):
    if os.path.isdir(cand) and cand not in sys.path:
        sys.path.insert(0, cand)

from pgsrip.pgs import (  # noqa: E402
    ObjectDefinitionSegment,
    PaletteDefinitionSegment,
    PgsReader,
    PresentationCompositionSegment,
    WindowDefinitionSegment,
)


class P:
    def __init__(self, n):
        self.name = n

    def __str__(self):
        return self.name


src = sys.argv[1]
limit = int(sys.argv[2]) if len(sys.argv) > 2 else 40

sets = list(PgsReader().decode(open(src, "rb").read(), P(os.path.basename(src))))

seg_mix = collections.Counter()
obj_counts = collections.Counter()
have_pcs = 0

for ds in sets:
    types = tuple(sorted({type(s).__name__.replace("Segment", "") for s in ds.segments}))
    seg_mix[types] += 1
    pcs = next((s for s in ds.segments if isinstance(s, PresentationCompositionSegment)), None)
    if pcs is not None:
        have_pcs += 1
        obj_counts[pcs.number_composition_objects] += 1

print("display sets 总数 :", len(sets))
print("含 PCS 的         :", have_pcs)
print()
print("段组合分布（前 12 种）:")
for k, v in seg_mix.most_common(12):
    print(f"  {v:>5}  {k}")
print()
print("PCS 的对象数分布 :", dict(obj_counts))
print()
print(f"=== 前 {limit} 个 display set 明细 ===")
for i, ds in enumerate(sets[:limit]):
    pcs = next((s for s in ds.segments if isinstance(s, PresentationCompositionSegment)), None)
    wds = next((s for s in ds.segments if isinstance(s, WindowDefinitionSegment)), None)
    ods = [s for s in ds.segments if isinstance(s, ObjectDefinitionSegment)]
    pds = [s for s in ds.segments if isinstance(s, PaletteDefinitionSegment)]
    pts = None
    for s in ds.segments:
        t = s.presentation_timestamp
        if t is not None:
            pts = t.hours * 3600 + t.minutes * 60 + t.seconds + t.milliseconds / 1000
            break
    nobj = pcs.number_composition_objects if pcs else None
    state = pcs.composition_state.name if pcs else "-"
    pal = pcs.palette_id if pcs else "-"
    win = f"({wds.x_offset},{wds.y_offset},{wds.width},{wds.height})" if wds else "-"
    objs = ""
    if pcs is not None:
        d = pcs.data
        pos = []
        for k in range(nobj or 0):
            j = 11 + k * 8
            if j + 8 <= len(d):
                oid = struct.unpack(">H", d[j : j + 2])[0]
                x = struct.unpack(">H", d[j + 4 : j + 6])[0]
                y = struct.unpack(">H", d[j + 6 : j + 8])[0]
                pos.append(f"#{oid}@{x},{y}")
        objs = " ".join(pos)
    print(
        f"  {i:>4} t={pts if pts is None else round(pts,3):<9} "
        f"nobj={nobj} state={state} pal={pal} win={win} "
        f"ods={len(ods)} pds={len(pds)} {objs}"
    )
