<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { api } from '../api'

/**
 * 弹幕渲染层。
 *
 * 定位：与视频字节流完全无关的旁路数据 —— 直接叠在画面上的 canvas，不碰解码。
 * 与 PGS 图片字幕层（.sub-overlay）是兄弟节点，靠 z-index 分层，互不干扰。
 *
 * 为什么不引库：整个渲染逻辑就是「按时间取弹幕 → 分轨道 → 画字」。引一个库要多背
 * 一份维护面和一套自己的配置约定，而这个项目的播放器已经有自己的坐标换算与
 * rAF 循环，自己写反而更短。
 *
 * 复用要点：画面是 object-fit: contain 放进盒子里的，所以弹幕的坐标系必须与
 * PGS 那套一致 —— 先算画面实际占的矩形，再在矩形内部分轨道。否则上下有黑边时
 * 弹幕会跑出画面。
 */
const props = defineProps({
  episodeId: { type: Number, required: true },
  // 用函数取 video 而不是直接传元素：子组件的 mounted 早于父组件的模板 ref 赋值，
  // 直接传会发生「挂载时拿到 null、之后永远不再更新」。函数每次调用都是最新的。
  getVideo: { type: Function, default: null },
  enabled: { type: Boolean, default: true },
  opacity: { type: Number, default: 0.9 },
  scale: { type: Number, default: 1 },
  blocked: { type: Array, default: () => [] },
  // 底部留给字幕的比例：PGS 字幕原位在 y≈998/1080，滚动弹幕压上去会糊成一团
  reserveBottom: { type: Number, default: 0.15 },
})

const emit = defineEmits(['loaded', 'fail'])

const canvasEl = ref(null)

const SCROLL_SEC = 8 // 滚动弹幕走完全程的秒数（业界通用值）
const FIXED_HOLD = 4 // 固定弹幕停留秒数
const SIZE_PX = [14, 18, 22] // 小 / 中 / 大，再乘 props.scale
const MAX_ONSCREEN = 200 // 同屏上限，超出直接丢新条（降速会让人感觉卡）
const DEDUP_MS = 3000 // 相同文本在该窗口内只显示一次

// 下面这些刻意不放进 reactive：每帧都要读写，挂上去只是白白产生依赖追踪开销
let ctx = null
let cssW = 0
let cssH = 0
let dpr = 1
let lineH = 0
let usableH = 0
let items = [] // 升序 [{t,mode,color,size,text}]
let cursor = 0
let active = []
let scrollTracks = []
let topTracks = []
let bottomTracks = []
const widthCache = new Map()
const lastSeen = new Map()
let blockedRe = null
let rafId = 0
let lastT = -1

const video = () => (props.getVideo ? props.getVideo() : null)

// ------------------------------------------------------------------
// 尺寸与轨道
// ------------------------------------------------------------------

function resize() {
  const el = canvasEl.value
  const v = video()
  if (!el || !v) return

  const w = v.clientWidth || 0
  const h = v.clientHeight || 0
  if (!w || !h) return

  const ratio = Math.min(2, window.devicePixelRatio || 1)
  if (w === cssW && h === cssH && ratio === dpr) return

  cssW = w
  cssH = h
  dpr = ratio
  // canvas 的物理尺寸要乘 DPR，否则高清屏上字是糊的
  el.width = Math.round(cssW * dpr)
  el.height = Math.round(cssH * dpr)
  el.style.width = cssW + 'px'
  el.style.height = cssH + 'px'

  ctx = el.getContext('2d')
  if (!ctx) return
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.textBaseline = 'top'

  buildTracks()
  widthCache.clear()
  resetFlight()
}

function buildTracks() {
  lineH = SIZE_PX[1] * props.scale * 1.35
  usableH = Math.max(lineH, cssH * (1 - props.reserveBottom))
  const n = Math.max(1, Math.floor(usableH / lineH))
  scrollTracks = Array.from({ length: n }, () => ({ t: -1e9, w: 0, speed: 0, right: 0 }))
  topTracks = Array.from({ length: n }, () => ({ freeAt: 0 }))
  bottomTracks = Array.from({ length: n }, () => ({ freeAt: 0 }))
}

// ------------------------------------------------------------------
// 数据
// ------------------------------------------------------------------

async function load() {
  try {
    // 行的顺序固定：[t_ms, mode, color, size, text]（后端为了省体积用了数组）
    const d = await api.get(`/api/episodes/${props.episodeId}/danmaku`)
    items = (d?.items || []).map((a) => ({
      t: Number(a[0]) || 0,
      mode: Number(a[1]) || 1,
      color: Number(a[2]) || 0xffffff,
      size: Number(a[3]) || 0,
      text: String(a[4] ?? ''),
    }))
    resetFlight()
    emit('loaded', items.length)
  } catch (e) {
    emit('fail', e?.message || '弹幕加载失败')
  }
}

/** 二分：第一个 t >= target 的下标 */
function lowerBound(arr, target) {
  let lo = 0
  let hi = arr.length
  while (lo < hi) {
    const mid = (lo + hi) >> 1
    if (arr[mid].t < target) lo = mid + 1
    else hi = mid
  }
  return lo
}

/** 播放位置跳变（seek / 换集）后清空重排，游标用二分重新定位 */
function resetFlight() {
  active = []
  for (const t of scrollTracks) {
    t.t = -1e9
    t.w = 0
    t.speed = 0
    t.right = 0
  }
  for (const t of topTracks) t.freeAt = 0
  for (const t of bottomTracks) t.freeAt = 0
  lastSeen.clear()

  const v = video()
  const nowMs = (v?.currentTime || 0) * 1000
  // 回看 300ms：刚飘进来的弹幕接上，更早的不补（补出来会挤成一团）
  cursor = lowerBound(items, nowMs - 300)
  lastT = v?.currentTime ?? -1
}

// ------------------------------------------------------------------
// 入列与分轨
// ------------------------------------------------------------------

function measure(text, sizePx) {
  const key = sizePx + '|' + text
  const hit = widthCache.get(key)
  if (hit !== undefined) return hit
  ctx.font = `bold ${sizePx}px sans-serif`
  const w = ctx.measureText(text).width
  if (widthCache.size > 5000) widthCache.clear()
  widthCache.set(key, w)
  return w
}

/**
 * 滚动弹幕找轨道。
 *
 * 关键不是「有没有空位」，而是**新的会不会追上旧的**：速度 = (画布宽 + 文本宽) / 时长，
 * 所以长文本更快，后发的长弹幕会吃掉前面的短弹幕。判定用两条：
 *   ① 旧条右边缘已经离开右边界（不重叠的起点条件）
 *   ② 新条更慢，或者两者的时间差已经够旧条走完（追不上的条件）
 */
function placeScroll(w, nowMs) {
  const speed = (cssW + w) / SCROLL_SEC
  for (let i = 0; i < scrollTracks.length; i++) {
    const tr = scrollTracks[i]
    if (tr.t < -1e8) {
      assignScroll(i, tr, w, speed, nowMs)
      return i
    }
    const dt = (nowMs - tr.t) / 1000
    const rightNow = tr.right - tr.speed * dt
    if (rightNow > cssW) continue // ① 还没让出右边的位置
    if (tr.speed >= speed) {
      assignScroll(i, tr, w, speed, nowMs)
      return i
    }
    // ② 旧条离场时新条是否已经追上
    const need = (SCROLL_SEC * w) / (cssW + w)
    if (dt >= need) {
      assignScroll(i, tr, w, speed, nowMs)
      return i
    }
  }
  return -1
}

function assignScroll(i, tr, w, speed, nowMs) {
  tr.t = nowMs
  tr.w = w
  tr.speed = speed
  tr.right = cssW + w // 入场瞬间的左边缘在 cssW，所以右边缘是 cssW + w
  void i
}

function enqueue(it, nowMs) {
  if (active.length >= MAX_ONSCREEN) return
  if (blockedRe && blockedRe.test(it.text)) return

  // 同屏去重：同一句话在 3 秒内只出现一次（刷屏时画面会干净很多）
  const seen = lastSeen.get(it.text)
  if (seen !== undefined && nowMs - seen < DEDUP_MS) return
  lastSeen.set(it.text, nowMs)

  const sizePx = SIZE_PX[it.size] * props.scale
  const w = measure(it.text, sizePx)
  const a = { ...it, sizePx, w, x: 0, y: 0, speed: 0, track: 0 }

  if (it.mode === 1) {
    const i = placeScroll(w, nowMs)
    if (i < 0) return // 轨道全满，丢掉这一条（不排队，避免延迟显示）
    a.track = i
    a.speed = (cssW + w) / SCROLL_SEC
  } else if (it.mode === 5) {
    const i = topTracks.findIndex((t) => t.freeAt <= nowMs)
    if (i < 0) return
    topTracks[i].freeAt = nowMs + FIXED_HOLD * 1000
    a.track = i
    a.y = i * lineH
  } else {
    const i = bottomTracks.findIndex((t) => t.freeAt <= nowMs)
    if (i < 0) return
    bottomTracks[i].freeAt = nowMs + FIXED_HOLD * 1000
    a.track = i
    a.y = usableH - (i + 1) * lineH
  }

  active.push(a)
}

/** 把到点的弹幕放进画面 */
function collect(nowMs) {
  while (cursor < items.length && items[cursor].t <= nowMs) {
    const it = items[cursor++]
    if (nowMs - it.t > 1200) continue // 早该飘走了，不补
    enqueue(it, nowMs)
  }
}

// ------------------------------------------------------------------
// 绘制
// ------------------------------------------------------------------

function draw(a, x, y) {
  ctx.font = `bold ${a.sizePx}px sans-serif`
  ctx.lineWidth = Math.max(2, a.sizePx / 7)
  ctx.strokeStyle = 'rgba(0,0,0,0.72)'
  ctx.strokeText(a.text, x, y)
  ctx.fillStyle = '#' + a.color.toString(16).padStart(6, '0')
  ctx.fillText(a.text, x, y)
}

function render(nowMs) {
  if (!ctx) return
  ctx.clearRect(0, 0, cssW, cssH)

  if (!props.enabled) {
    active = []
    return
  }

  ctx.globalAlpha = Math.max(0.15, Math.min(1, props.opacity))
  ctx.lineJoin = 'round'

  const keep = []
  for (const a of active) {
    const el = (nowMs - a.t) / 1000
    let x
    let y
    if (a.mode === 1) {
      x = cssW - a.speed * el
      if (x + a.w < 0) continue // 完全出屏
      y = a.track * lineH
    } else {
      if (el > FIXED_HOLD) continue
      x = (cssW - a.w) / 2
      y = a.y
    }
    draw(a, x, y)
    keep.push(a)
  }
  active = keep
  ctx.globalAlpha = 1
}

function frame() {
  rafId = requestAnimationFrame(frame)
  const v = video()
  if (!v) return

  resize()
  if (!ctx || !cssW) return

  const t = v.currentTime || 0
  // seek 检测：时间不是小幅前进就当作跳转，清屏重排
  if (lastT >= 0 && (t < lastT - 0.5 || t > lastT + 2.5)) {
    resetFlight()
  }
  lastT = t

  const nowMs = t * 1000
  if (props.enabled) collect(nowMs)
  render(nowMs)
}

// ------------------------------------------------------------------
// 对外方法：自己发的弹幕立刻可见（乐观更新）
// ------------------------------------------------------------------

function addLocal(o) {
  const it = {
    t: Number(o.t_ms) || 0,
    mode: Number(o.mode) || 1,
    color: Number(o.color) || 0xffffff,
    size: Number(o.size) || 0,
    text: String(o.text ?? ''),
  }
  if (!it.text) return

  const at = lowerBound(items, it.t)
  items.splice(at, 0, it)
  if (at < cursor) cursor++ // 游标要跟着后移，否则会漏掉一条

  const v = video()
  enqueue(it, (v?.currentTime || 0) * 1000)
}

defineExpose({ addLocal, reload: load, reset: resetFlight })

// ------------------------------------------------------------------
// 生命周期
// ------------------------------------------------------------------

function rebuildBlocked() {
  const list = (props.blocked || []).map((s) => String(s).trim()).filter(Boolean)
  if (!list.length) {
    blockedRe = null
    return
  }
  // 用户输入的是普通字符串，必须转义 —— 否则一个 "(" 就能让整条规则失配
  const safe = list.map((s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  try {
    blockedRe = new RegExp(safe.join('|'), 'i')
  } catch {
    blockedRe = null
  }
}

watch(() => props.episodeId, load)
watch(() => props.enabled, (on) => {
  if (!on) {
    active = []
    if (ctx) ctx.clearRect(0, 0, cssW, cssH)
  } else {
    resetFlight()
  }
})
watch(() => props.scale, () => {
  widthCache.clear()
  buildTracks()
  resetFlight()
})
watch(() => props.blocked, rebuildBlocked, { deep: true })

onMounted(() => {
  rebuildBlocked()
  resize()
  load()
  rafId = requestAnimationFrame(frame)
})

onBeforeUnmount(() => {
  if (rafId) cancelAnimationFrame(rafId)
  rafId = 0
})
</script>

<template>
  <canvas ref="canvasEl" class="dm-layer" />
</template>

<style scoped>
/* 覆盖整个播放器盒子，但不吃鼠标事件 —— 否则会挡住控制条 */
.dm-layer {
  position: absolute;
  inset: 0;
  z-index: 2;
  pointer-events: none;
}
</style>
