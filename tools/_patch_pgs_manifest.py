"""给 pgs_to_images.py 的 manifest 补上画布尺寸（前端按视频实际显示尺寸缩放要用）。

坑：字面量里带 () 时不能直接丢给 re —— 会被当成分组，匹配静默失败。
所有字面量一律 re.escape。
"""
import re

p = 'bin/pgs_to_images.py'
s = open(p, encoding='utf-8').read()
NL = chr(10)


def sub(pat, rep, regex=False):
    global s
    s2, n = re.subn(pat if regex else re.escape(pat), rep, s, count=1)
    assert n == 1, pat
    s = s2


# 1) 清屏分支（这里要保留空白，用真正则）
sub(r'return None, None(\s+)# 清屏', r'return None, None, size\1# 清屏', regex=True)
# 2) 调色板缺失分支
sub('    if palettes is None:' + NL + '        return None, None' + NL,
    '    if palettes is None:' + NL + '        return None, None, size' + NL)
# 3) 画布尺寸改为用 PCS 里读到的 size
sub('    W, H = pcs.width or 1920, pcs.height or 1080' + NL, '    W, H = size' + NL)
# 4) 空画布分支
sub('    if not rows.any() or not cols.any():' + NL + '        return None, None' + NL,
    '    if not rows.any() or not cols.any():' + NL + '        return None, None, size' + NL)
# 5) 正常返回也带上 size
sub('    return canvas, (int(rx[0]), int(ry[0]), int(rx[-1]), int(ry[-1]))' + NL,
    '    return canvas, (int(rx[0]), int(ry[0]), int(rx[-1]), int(ry[-1])), size' + NL)
# 6) 主循环里记下画布尺寸
sub('    prev_canvas: np.ndarray | None = None' + NL + '    seq = 0' + NL,
    '    prev_canvas: np.ndarray | None = None' + NL
    + '    canvas_size: tuple[int, int] | None = None' + NL
    + '    seq = 0' + NL)
sub('        canvas, bbox = render(ds.segments, obj_cache, pal_cache, img_cache)' + NL,
    '        canvas, bbox, csize = render(ds.segments, obj_cache, pal_cache, img_cache)' + NL
    + '        if csize is not None:' + NL
    + '            canvas_size = csize' + NL)
# 7) manifest 里写进 width / height
sub('        "format": args.format,' + NL + '        "count": len(cues),',
    '        "format": args.format,' + NL
    + '        "width": (canvas_size or (1920, 1080))[0],' + NL
    + '        "height": (canvas_size or (1920, 1080))[1],' + NL
    + '        "count": len(cues),')

open(p, 'w', encoding='utf-8').write(s)
print('ok · manifest 含 width/height:', '"width": (canvas_size' in s)
print('ok · render 返回三元组:', 'return canvas, (int(rx[0])' in s and '), size' in s)
