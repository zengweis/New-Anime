#!/usr/bin/env python3
"""PGS(.sup) → 每屏一张透明图片 + 时间清单，供前端叠加在视频上播放。

为什么走这条路：
  位图字幕（PGS）是图像，转文本要靠 OCR（每轨约 8 分钟 CPU，片头曲/特效还会出错字）。
  直接按 PGS 自带的「显示时刻 + 位置 + 调色板」把画面渲成透明图片叠加到视频上，
  100% 保真（原样式、特效、位置都对），CPU 只花一分钟左右。

    python3 pgs_to_images.py <in.sup> <outDir> [--threshold=0.02] [--max=3000]
                             [--format=webp|png] [--quality=90]

输出：
    <outDir>/00001.webp …     裁剪到内容的透明图片
    <outDir>/manifest.json    {version,count,format,cues:[{s,e,x,y,w,h,f}]}

实现要点（PGS 格式里几个反直觉的地方，都是实测撞出来的）：
  1. 一个 .sup 由 PG 段组成，按 END 段切成「display set」；每个 display set 就是一次屏幕更新，
     天然对应「一屏字幕」。没有 PCS 或对象数为 0 的 display set = 清屏（字幕结束）。
  2. **对象坐标是屏幕绝对坐标**，不是相对窗口的。按「相对窗口」理解会把对象全贴到
     一个几百像素高的小画布上、越界丢弃 —— 实测 1009 屏里 931 屏被误判成空屏。
     证据：窗口 (795,1026) 的对象坐标也正是 (795,1026)，即「绝对 = 窗口原点 + 相对(0,0)」。
     所以画布必须是整张 1920x1080，画完再按 alpha 裁紧。
  3. **对象/调色板要跨屏缓存**：PCS 可以引用先前 display set 里定义过的 ODS/PDS（不重发），
     只有 EPOCH_START 才清空。
  4. 渐进显示（淡入/逐字）会产生大量几乎相同的 display set（间隔仅 40ms）。
     逐屏出图会有上千张、体积失控 —— 按「与上一屏的像素差异比例」合并，
     差异小于阈值就只延长上一屏。必须在**未裁剪的全画布**上比较：
     裁剪框会逐帧抖动 1px，拿裁剪后的图比会因为尺寸/偏移不同而永远合不上。
"""

from __future__ import annotations

import argparse
import json
import os
import struct
import sys

# 复用 pgsrip 自带的解析器（独立 venv 里），省掉自己实现 RLE 解码
for cand in ("/opt/anime-new/venv-ocr/lib/python3.12/site-packages", os.environ.get("PGS_VENV", "")):
    if cand and os.path.isdir(cand) and cand not in sys.path:
        sys.path.insert(0, cand)

import cv2  # noqa: E402
import numpy as np  # noqa: E402

from pgsrip.pgs import (  # noqa: E402
    CompositionState,
    ObjectDefinitionSegment,
    ObjectSequenceType,
    PaletteDefinitionSegment,
    PgsImage,
    PgsReader,
    PresentationCompositionSegment,
)


class _Path:
    """MediaPath 的替身：解析器只用它记日志。"""

    def __init__(self, name: str) -> None:
        self.name = name

    def __str__(self) -> str:
        return self.name


def composition_objects(pcs: PresentationCompositionSegment) -> list[tuple[int, int, int]]:
    """PCS 组合对象表，每条 8 字节：object_id(2) window_id(1) flags(1) x(2) y(2)。

    pgsrip 只暴露了对象个数，坐标要自己解。坐标是屏幕绝对坐标（见文件头说明）。
    """
    out: list[tuple[int, int, int]] = []
    d = pcs.data
    for k in range(pcs.number_composition_objects or 0):
        i = 11 + k * 8
        if i + 8 > len(d):
            break
        out.append((
            struct.unpack(">H", d[i : i + 2])[0],
            struct.unpack(">H", d[i + 4 : i + 6])[0],
            struct.unpack(">H", d[i + 6 : i + 8])[0],
        ))
    return out


def pts_seconds(seg) -> float | None:
    t = seg.presentation_timestamp
    if t is None:
        return None
    try:
        return (t.hours * 3600 + t.minutes * 60 + t.seconds) + t.milliseconds / 1000.0
    except Exception:  # noqa: BLE001
        return None


def render(segments, obj_cache, pal_cache, img_cache):
    """渲一个 display set 到全屏 RGBA 画布；返回 (画布, 内容包围盒, 画布尺寸) 或 (None,None,画布尺寸)。"""
    pcs = next((s for s in segments if isinstance(s, PresentationCompositionSegment)), None)
    if pcs is None:
        return None, None, None

    size = (int(pcs.width or 1920), int(pcs.height or 1080))

    if pcs.composition_state == CompositionState.EPOCH_START:   # 播放位置跳转，缓存失效
        obj_cache.clear()
        pal_cache.clear()

    for s in segments:
        if not isinstance(s, ObjectDefinitionSegment):
            continue
        oid = s.id
        if oid is None:
            continue
        seq = s.sequence_type
        if seq in (ObjectSequenceType.FIRST, ObjectSequenceType.FIRST_AND_LAST):
            obj_cache[oid] = bytearray(s.img_data)
            img_cache.pop((oid, pcs.palette_id), None)
        else:                                                    # MIDDLE / LAST
            obj_cache.setdefault(oid, bytearray()).extend(s.img_data)

    for s in segments:
        if isinstance(s, PaletteDefinitionSegment):
            pal_cache[s.palette_id] = s.palettes

    objs = composition_objects(pcs)
    if not objs:
        return None, None, size                                        # 清屏

    palettes = pal_cache.get(pcs.palette_id) or (next(iter(pal_cache.values()), None))
    if palettes is None:
        return None, None, size

    W, H = size
    canvas = np.zeros((H, W, 4), dtype=np.uint8)
    drew = False
    for oid, ox, oy in objs:
        data = obj_cache.get(oid)
        if data is None:
            continue
        key = (oid, pcs.palette_id)
        img = img_cache.get(key)
        if img is None:
            try:
                img = PgsImage(bytes(data), palettes).decode_rle_image(bytes(data), palettes, binary=False)
            except Exception:  # noqa: BLE001
                continue
            if img is None or img.size == 0:
                continue
            img_cache[key] = img
        h, w = img.shape[:2]
        sx, sy = max(0, -ox), max(0, -oy)
        dx, dy = max(0, ox), max(0, oy)
        cw, ch = min(w - sx, W - dx), min(h - sy, H - dy)
        if cw <= 0 or ch <= 0:
            continue
        canvas[dy : dy + ch, dx : dx + cw] = img[sy : sy + ch, sx : sx + cw]
        drew = True

    if not drew:
        return None, None

    alpha = canvas[:, :, 3]
    rows, cols = np.any(alpha, axis=1), np.any(alpha, axis=0)
    if not rows.any() or not cols.any():
        return None, None, size
    ry, rx = np.flatnonzero(rows), np.flatnonzero(cols)
    return canvas, (int(rx[0]), int(ry[0]), int(rx[-1]), int(ry[-1])), size


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("sup")
    ap.add_argument("outdir")
    ap.add_argument("--threshold", type=float, default=0.02,
                    help="与上一屏的像素差异比例低于此值则该屏不单独出图（合并）")
    ap.add_argument("--max", type=int, default=3000)
    ap.add_argument("--format", default="webp", choices=["webp", "png"])
    ap.add_argument("--quality", type=int, default=90, help="webp 质量（有损；90 肉眼无差）")
    args = ap.parse_args()

    os.makedirs(args.outdir, exist_ok=True)
    for f in os.listdir(args.outdir):
        if f == "manifest.json" or f.rsplit(".", 1)[-1] in ("png", "webp"):
            os.remove(os.path.join(args.outdir, f))

    data = open(args.sup, "rb").read()
    sets = list(PgsReader().decode(data, _Path(os.path.basename(args.sup))))

    obj_cache: dict[int, bytearray] = {}
    pal_cache: dict[int, list] = {}
    img_cache: dict[tuple[int, int], np.ndarray] = {}

    cues: list[dict] = []
    cur: dict | None = None
    prev_canvas: np.ndarray | None = None
    canvas_size: tuple[int, int] | None = None
    seq = 0
    stats = {"display_sets": len(sets), "blank": 0, "merged": 0, "written": 0}

    def flush(end: float) -> None:
        nonlocal cur
        if cur is not None and end > cur["s"]:
            cur["e"] = round(end, 3)
            cues.append(cur)
        cur = None

    for idx, ds in enumerate(sets):
        start = None
        for s in ds.segments:
            start = pts_seconds(s)
            if start is not None:
                break
        if start is None:
            continue
        end = None
        if idx + 1 < len(sets):
            for s in sets[idx + 1].segments:
                end = pts_seconds(s)
                if end is not None:
                    break
        if end is None or end <= start:
            end = start + 0.2

        canvas, bbox, csize = render(ds.segments, obj_cache, pal_cache, img_cache)
        if csize is not None:
            canvas_size = csize

        if canvas is None:
            stats["blank"] += 1
            flush(start)
            prev_canvas = None
            continue

        # 渐进显示去重：在**全画布**上比较（裁剪框会抖 1px，拿裁剪图比永远合不上）
        if prev_canvas is not None and cur is not None and prev_canvas.shape == canvas.shape:
            diff = np.count_nonzero(np.any(prev_canvas != canvas, axis=2))
            if diff / float(canvas.shape[0] * canvas.shape[1]) < args.threshold:
                stats["merged"] += 1
                cur["e"] = round(end, 3)
                prev_canvas = canvas
                continue

        flush(start)
        if stats["written"] >= args.max:
            break

        x0, y0, x1, y1 = bbox
        crop = canvas[y0 : y1 + 1, x0 : x1 + 1]
        seq += 1
        name = f"{seq:05d}.{args.format}"
        path = os.path.join(args.outdir, name)
        if args.format == "webp":
            cv2.imwrite(path, crop, [cv2.IMWRITE_WEBP_QUALITY, args.quality])
        else:
            cv2.imwrite(path, crop, [cv2.IMWRITE_PNG_COMPRESSION, 9])
        if not os.path.exists(path):
            seq -= 1
            continue

        stats["written"] += 1
        cur = {"s": round(start, 3), "e": round(end, 3),
               "x": x0, "y": y0, "w": int(crop.shape[1]), "h": int(crop.shape[0]), "f": name}
        prev_canvas = canvas

    if cur is not None:
        cur["e"] = max(cur["e"], cur["s"] + 0.2)
        cues.append(cur)

    manifest = {
        "version": 1,
        "source": os.path.basename(args.sup),
        "format": args.format,
        "width": (canvas_size or (1920, 1080))[0],
        "height": (canvas_size or (1920, 1080))[1],
        "count": len(cues),
        "stats": stats,
        "cues": cues,
    }
    with open(os.path.join(args.outdir, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, separators=(",", ":"))

    total = sum(os.path.getsize(os.path.join(args.outdir, f)) for f in os.listdir(args.outdir))
    print(json.dumps({"ok": True, "format": args.format, "bytes": total, **stats,
                      "cues": len(cues), "outdir": args.outdir}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
