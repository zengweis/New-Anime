<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PlaysVideoEngine } from 'playsvideo'
import DanmakuLayer from '../components/DanmakuLayer.vue'
import { api, streamUrl, subtitleUrl, subtitleAssetUrl, getToken, epLabel, formatSize } from '../api'
import { toast, toastErr } from '../store/ui'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const data = ref(null)
const videoEl = ref(null)
const errMsg = ref('')
const hint = ref('')
const copied = ref(false)
const stage = ref('')
const pipeline = ref(null)
const tracks = ref([])
const activeTrack = ref(-1)
const serverTracks = ref([])
const subNote = ref('')

// ---- 图片字幕（位图字幕 PGS 渲成的透明图片，按时间叠在画面上）----
const imageTracks = computed(() => serverTracks.value.filter((t) => t.kind === 'image'))
const textTracks = computed(() => serverTracks.value.filter((t) => t.kind !== 'image'))
const imageSub = ref(null) // { index, man }
const imgCue = ref(null)
const imgBox = ref({ left: '0px', top: '0px', width: '0px', height: '0px' })
let rafId = 0
let resizeObs = null

// ------------------------------------------------------------------
// 弹幕：偏好存 localStorage（弹幕是纯客户端渲染，服务端不关心这些开关）
// ------------------------------------------------------------------
const LS = {
  get(k, d = '') {
    try {
      return localStorage.getItem(k) ?? d
    } catch {
      return d
    }
  },
  set(k, v) {
    try {
      localStorage.setItem(k, v)
    } catch {
      /* 隐私模式下忽略 */
    }
  },
}

const dmEl = ref(null)
const dmOn = ref(LS.get('anime_dm_on', '1') !== '0')
const dmOpacity = ref(Number(LS.get('anime_dm_opacity', '0.9')) || 0.9)
const dmScale = ref(Number(LS.get('anime_dm_scale', '1')) || 1)
const dmBlocked = ref(
  LS.get('anime_dm_blocked', '')
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean)
)
const dmBlockText = ref(LS.get('anime_dm_blocked', ''))
const dmCount = ref(0)
const dmErr = ref('')
const dmText = ref('')
const dmSending = ref(false)
const dmCfgOpen = ref(false)

watch(dmOn, (v) => LS.set('anime_dm_on', v ? '1' : '0'))
watch(dmOpacity, (v) => LS.set('anime_dm_opacity', String(v)))
watch(dmScale, (v) => LS.set('anime_dm_scale', String(v)))

function applyBlocked() {
  dmBlocked.value = dmBlockText.value
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean)
  LS.set('anime_dm_blocked', dmBlockText.value)
  toast(`已应用 ${dmBlocked.value.length} 条屏蔽词`)
}

/**
 * 发送弹幕。
 *
 * 时间取「当前播放位置 - 1.5 秒」：用户是听到一句话之后才开始打字的，
 * 用点发送那一刻的时间会让弹幕出现在台词之后，看起来像错位。
 */
async function sendDanmaku() {
  const text = dmText.value.trim()
  if (!text || dmSending.value || !ep.value) return

  const v = videoEl.value
  const tMs = Math.max(0, Math.round(((v?.currentTime || 0) - 1.5) * 1000))

  dmSending.value = true
  try {
    const r = await api.post(`/api/episodes/${ep.value.id}/danmaku`, {
      t_ms: tMs,
      text,
      mode: 1,
      color: 0xffffff,
      size: 1,
    })
    // 乐观插入：自己发的立刻可见，不用等下一次拉取
    dmEl.value?.addLocal(r)
    dmText.value = ''
    dmCount.value += 1
    toast('弹幕已发送')
  } catch (e) {
    toastErr(e)
  } finally {
    dmSending.value = false
  }
}

let timer = null
let seeked = false
let engine = null
let engineReady = false
let pendingResume = 0

const ep = computed(() => data.value?.episode || null)
const anime = computed(() => data.value?.anime || null)
const title = computed(() => anime.value?.title_cn || anime.value?.title || '')
const plan = computed(() => ep.value?.playback || null)

/**
 * 只有容器本身浏览器认（mp4/webm）才把地址交给 <video>；
 * mkv / avi / ts 一律交给前端解封装引擎接管 src。
 */
const useEngine = computed(() => !!ep.value && plan.value?.mode !== 'direct')
const nativeSrc = computed(() => (!useEngine.value && ep.value ? streamUrl(ep.value.id) : ''))
const dlHref = computed(() => (ep.value ? streamUrl(ep.value.id, true) : ''))

const pipelineLabel = computed(() => {
  const p = pipeline.value
  if (!p) return ''
  const parts = [p.mode === 'passthrough' ? '原生直通' : '前端解封装']
  const v = String(p.vcodec || '').toUpperCase()
  const a = String(p.acodec || '').toUpperCase()
  if (v || a) parts.push([v, a].filter(Boolean).join(' / '))
  return parts.join(' · ')
})

// ------------------------------------------------------------------
// 能力探测
// ------------------------------------------------------------------

/** 浏览器是否具备 HEVC 硬解能力：Chrome / Edge 有，Firefox 与 Chromium 内核没有 */
function hevcCapable() {
  try {
    if (typeof MediaSource === 'undefined' || typeof MediaSource.isTypeSupported !== 'function') {
      return false
    }
    return (
      MediaSource.isTypeSupported('video/mp4;codecs="hvc1.2.4.L123.B0"') ||
      MediaSource.isTypeSupported('video/mp4;codecs="hev1.2.4.L123.B0"')
    )
  } catch {
    return false
  }
}

function fmtClock(sec) {
  const s = Math.max(0, Math.floor(Number(sec) || 0))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

// ------------------------------------------------------------------
// 加载
// ------------------------------------------------------------------

function teardown() {
  if (engine) {
    try {
      engine.destroy()
    } catch {
      /* 引擎已释放 */
    }
    engine = null
  }
  engineReady = false
  const el = videoEl.value
  if (el) {
    try {
      el.removeAttribute('src')
      el.load()
    } catch {
      /* 无视 */
    }
  }
}

async function load() {
  teardown()
  loading.value = true
  errMsg.value = ''
  hint.value = ''
  stage.value = ''
  pipeline.value = null
  tracks.value = []
  activeTrack.value = -1
  serverTracks.value = []
  subNote.value = ''
  clearImageSub()
  dmCount.value = 0
  dmErr.value = ''
  dmText.value = ''
  seeked = false
  pendingResume = 0

  try {
    data.value = await api.get(`/api/episodes/${route.params.id}`)
  } catch (e) {
    toastErr(e)
    data.value = null
  }
  loading.value = false

  const pos = data.value?.progress?.position_sec
  if (pos) pendingResume = Number(pos)

  const pl = data.value?.episode?.playback
  if (pl?.probed && pl.hevc && !hevcCapable()) {
    hint.value =
      `这个片源是 HEVC${pl.high_depth ? ' 10bit' : ''}，当前浏览器没有 HEVC 硬件解码能力，` +
      '多半只能出声或黑屏。Chrome / Edge（Windows、macOS）可硬解；Firefox 与 Chromium 内核不支持。'
  }

  await nextTick()
  if (!ep.value) return

  await prepareSubtitles()
  if (useEngine.value) startEngine()
}

// ------------------------------------------------------------------
// 字幕准备：一切以服务端抽好的 WebVTT 为准
// ------------------------------------------------------------------

/**
 * 字幕只有服务端一条路。原因：让前端解封装引擎自己去扫 mkv 里的内嵌字幕轨
 * 实测约 1.26x 实时，一集 23 分钟的片要等约 19 分钟——等于看完才出字幕。
 * 服务端 ffmpeg 只解封装不解视频码，实测 0.48 秒，抽完缓存下来，
 * 之后每次播放都是直接拉一个几十 KB 的 VTT。
 */
async function prepareSubtitles() {
  const state = ep.value?.subtitle_state || {}
  serverTracks.value = Array.isArray(ep.value?.subtitles) ? ep.value.subtitles : []
  subNote.value = state.error || ''

  // 已有轨，或服务端明确尝试过（含「确认无字幕」「位图字幕不可转」）——都不用再抽
  if (serverTracks.value.length || state.extracted_at) return
  // 连内嵌字幕轨都没有，抽也抽不出东西
  if (Number(state.embedded || 0) <= 0) return

  stage.value = '正在准备字幕…'
  try {
    const r = await api.post(`/api/episodes/${route.params.id}/subtitles/extract`)
    if (Array.isArray(r?.tracks) && r.tracks.length) serverTracks.value = r.tracks
    if (r?.error) subNote.value = r.error
  } catch {
    /* 抽取失败不该挡住看片，静默降级 */
  }
  stage.value = ''
}

/**
 * 是否关掉引擎的前端内嵌字幕扫描（只影响「自动选中」，见下）。
 */
function embeddedSubtitlePolicy() {
  const state = ep.value?.subtitle_state || {}
  return serverTracks.value.length || state.extracted_at ? 'off' : 'auto'
}

/**
 * playsvideo 没有提供真正关闭「内嵌字幕扫描」的开关。
 * EngineOptions.embeddedSubtitlePolicy 只决定要不要自动选中；真正的提取是
 * 无条件 fire-and-forget 的，而且分两条路径、两个不同的入口：
 *   - loadUrl（Worker 路径，我们走的就是这条）→ requestEmbeddedSubtitleTrack()
 *   - loadSource（主线程路径）              → extractEmbeddedSubtitlesFromDemux()
 * 实测那趟按约 1.26x 实时扫全片，一集 23 分钟要等约 19 分钟；同样的字幕
 * 服务端 0.5 秒就抽好了，所以这里在实例上把两个入口都短路掉。
 * 库升级若改了方法名，这里会静默失效——只退化成「多等一会儿 + 多一份重复轨」，不影响播放。
 */
function disableEmbeddedSubtitleScan(eng) {
  const noop = () => {}

  if (typeof eng.requestEmbeddedSubtitleTrack === 'function') {
    eng.requestEmbeddedSubtitleTrack = noop // Worker 路径的闸门
  }
  if (typeof eng.extractEmbeddedSubtitlesFromDemux === 'function') {
    eng.extractEmbeddedSubtitlesFromDemux = (demux, options = {}) => {
      // 保持库原本的释放语义，避免 passthrough 分支残留 demux
      if (options?.releaseAfterComplete && eng._sourceDemux === demux) {
        try {
          demux.dispose()
          eng._sourceDemux = null
          eng._source = null
        } catch {
          /* 释放失败不影响播放 */
        }
      }
      return Promise.resolve()
    }
  }

  // 既然我们主动不扫了，就别再往外报「正在提取 N 条字幕轨」这种会卡住的状态文案
  const embeddedChatter = /^(Extracting \d+ subtitle track|No embedded subtitles|Subtitle track \d+:)/i
  const origStatus = eng.dispatchSubtitleStatus?.bind(eng)
  if (origStatus) {
    eng.dispatchSubtitleStatus = (msg) => {
      if (typeof msg === 'string' && embeddedChatter.test(msg)) return
      origStatus(msg)
    }
  }
}

/**
 * 用户也可能用浏览器自带的字幕菜单切轨，那样不会经过 pickTrack()，
 * 按钮高亮就会撒谎。监听 textTrack 的 change 事件把它同步回来。
 */
const boundTracks = new WeakSet()
function bindTrackEvents() {
  const tt = videoEl.value?.textTracks
  if (!tt) return
  Array.from(tt).forEach((t) => {
    if (boundTracks.has(t)) return
    boundTracks.add(t)
    t.addEventListener('change', refreshTracks)
  })
}

/**
 * <track> 是浏览器异步加载的，textTracks 要过一会儿才齐。
 * 轮询到数量对上为止（最多 6 秒），而不是写死一个 sleep。
 */
function trackSyncLoop() {
  let tries = 0
  const tick = () => {
    bindTrackEvents()
    refreshTracks()
    if (tracks.value.length >= serverTracks.value.length) {
      if (tracks.value.length && activeTrack.value < 0) pickTrack(0)
      // 只有图片字幕（没有文本轨）时自动打开叠加层，否则用户会以为这集没字幕
      if (!tracks.value.length && imageTracks.value.length && !imageSub.value) {
        toggleImageSub(imageTracks.value[0])
      }
      return
    }
    if (++tries < 12) setTimeout(tick, 500)
  }
  tick()
}

// ------------------------------------------------------------------
// 前端解封装引擎
// ------------------------------------------------------------------

/** 字幕轨一律用 <track> 直连服务端 VTT（引擎自带的 loadExternalSubtitle 一次只能挂一条） */
const trackSrc = (i) => subtitleUrl(ep.value.id, i)

function startEngine() {
  const el = videoEl.value
  if (!el || !ep.value) return

  stage.value = '正在解封装…'
  try {
    // 关键：有服务端字幕就关掉引擎的前端内嵌扫描，否则会白等十几分钟
    engine = new PlaysVideoEngine(el, { embeddedSubtitlePolicy: embeddedSubtitlePolicy() })
    disableEmbeddedSubtitleScan(engine)
  } catch (e) {
    errMsg.value = `播放引擎初始化失败：${e?.message || e}`
    stage.value = ''
    return
  }

  engine.addEventListener('loading', () => {
    stage.value = '正在解封装…'
  })
  engine.addEventListener('subtitle-status', (e) => {
    const m = e?.detail?.message
    if (m) stage.value = m
  })
  engine.addEventListener('ready', (e) => {
    const d = e?.detail || {}
    const path = d.codecPath || {}
    pipeline.value = {
      mode: path.mode || 'pipeline',
      passthrough: !!d.passthrough,
      vcodec: path.outputVideo?.codec || path.sourceVideo?.codec || '',
      acodec: path.outputAudio?.codec || path.sourceAudio?.codec || '',
      segments: Number(d.totalSegments) || 0,
      duration: Number(d.durationSec) || 0,
    }
    engineReady = true
    stage.value = ''
    applyResume()
    refreshTracks()
    // <track> 由模板渲染（服务端 URL 直连），等浏览器异步加载完再同步一次
    trackSyncLoop()
  })
  engine.addEventListener('error', (e) => {
    engineReady = false
    stage.value = ''
    onEngineError(e?.detail?.message || '')
  })

  // 引擎在 worker 里对源文件发 Range 请求，必须用绝对地址
  engine.loadUrl(new URL(streamUrl(ep.value.id), location.origin).href)
}

function onEngineError(msg) {
  const pl = plan.value || {}
  if (pl.hevc && !hevcCapable()) {
    errMsg.value =
      `播放失败：${msg || '当前浏览器不支持 HEVC 解码'}。` +
      `该片源是 HEVC${pl.high_depth ? ' 10bit' : ''}，需要 Chrome / Edge 且硬件支持解码；` +
      'Firefox 与 Chromium 内核不支持。也可点「复制直链」用 PotPlayer / VLC 打开。'
    return
  }
  errMsg.value = `播放失败：${msg || '解封装出错'}。可以点「复制直链」用本地播放器打开。`
}

// ------------------------------------------------------------------
// 字幕轨
// ------------------------------------------------------------------

function refreshTracks() {
  const tt = videoEl.value?.textTracks
  if (!tt) return
  tracks.value = Array.from(tt).map((t, i) => ({
    i,
    label: t.label || t.language || `字幕 ${i + 1}`,
    showing: t.mode === 'showing',
  }))
  const cur = tracks.value.find((t) => t.showing)
  activeTrack.value = cur ? cur.i : -1
}

function pickTrack(i) {
  const tt = videoEl.value?.textTracks
  if (!tt) return
  Array.from(tt).forEach((t, idx) => {
    t.mode = idx === i ? 'showing' : 'disabled'
  })
  activeTrack.value = i
  clearImageSub() // 文本字幕与图片叠加层互斥：选了文本就收起图片层
}

// ------------------------------------------------------------------
// 图片字幕：位图字幕（PGS）渲成的透明图片，按 PGS 自带的时机与位置叠在画面上
//
// 为什么不走 <track>：WebVTT 只能表达文字，表达不了图像。位图字幕做文本只能靠 OCR，
// 而 OCR 一轨要十几分钟，片头曲/特效/画面标牌还会识别出错字。直接把原画渲成图片
// 叠加，100% 保真，渲染一轨只要一分多钟。代价是要自己按时间切换图片、自己算位置。
// ------------------------------------------------------------------

/** 二分找当前时刻该显示哪一屏（一集约 500 屏，逐帧线性找也行，但二分更稳） */
function findCue(cues, t) {
  let lo = 0
  let hi = cues.length - 1
  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    const c = cues[mid]
    if (t < c.s) hi = mid - 1
    else if (t > c.e) lo = mid + 1
    else return c
  }
  return null
}

/**
 * 把「源画布坐标」换算成屏幕坐标。
 * 视频是被 object-fit:contain 居中放进盒子的，所以要先算出画面实际占的矩形，
 * 再按源画布尺寸缩放 —— 直接按元素宽高算会在上下有黑边时错位。
 */
function layoutCue(c) {
  const el = videoEl.value
  const m = imageSub.value?.man
  if (!el || !m || !c) return
  const vw = el.videoWidth || m.width || 1920
  const vh = el.videoHeight || m.height || 1080
  const bw = el.clientWidth || 0
  const bh = el.clientHeight || 0
  if (!bw || !bh) return
  const scale = Math.min(bw / vw, bh / vh)
  const dw = vw * scale
  const dh = vh * scale
  const ox = (bw - dw) / 2
  const oy = (bh - dh) / 2
  const sx = dw / (m.width || vw)
  const sy = dh / (m.height || vh)
  imgBox.value = {
    left: Math.round(ox + c.x * sx) + 'px',
    top: Math.round(oy + c.y * sy) + 'px',
    width: Math.round(c.w * sx) + 'px',
    height: Math.round(c.h * sy) + 'px',
  }
}

/** 预取接下来几屏：等到切屏那一刻才去下载会闪一下白 */
function preloadAhead(cues, i, n = 3) {
  for (let k = i + 1; k <= Math.min(i + n, cues.length - 1); k++) {
    const im = new Image()
    im.src = subtitleAssetUrl(ep.value.id, imageSub.value.index, cues[k].f)
  }
}

function tick() {
  rafId = requestAnimationFrame(tick)
  const el = videoEl.value
  const sub = imageSub.value
  if (!el || !sub) return
  const cues = sub.man.cues || []
  const c = findCue(cues, el.currentTime)
  if (c === imgCue.value) return
  imgCue.value = c
  if (!c) return
  layoutCue(c)
  preloadAhead(cues, cues.indexOf(c))
}

function startRaf() {
  if (!rafId) rafId = requestAnimationFrame(tick)
}

function stopRaf() {
  if (rafId) {
    cancelAnimationFrame(rafId)
    rafId = 0
  }
}

function clearImageSub() {
  imageSub.value = null
  imgCue.value = null
  stopRaf()
}

async function toggleImageSub(t) {
  if (imageSub.value?.index === t.index) {
    clearImageSub()
    return
  }
  try {
    const res = await fetch(subtitleAssetUrl(ep.value.id, t.index, 'manifest.json'))
    if (!res.ok) throw new Error('清单加载失败 ' + res.status)
    const man = await res.json()
    if (!Array.isArray(man.cues) || !man.cues.length) throw new Error('清单里没有字幕')
    // 与文本字幕互斥
    const tt = videoEl.value?.textTracks
    if (tt) Array.from(tt).forEach((x) => { x.mode = 'disabled' })
    activeTrack.value = -1
    imageSub.value = { index: t.index, man }
    imgCue.value = null
    startRaf()
    // 尺寸变化（窗口缩放、进全屏）会改变画面矩形，重新排版一次
    if (!resizeObs && videoEl.value) {
      resizeObs = new ResizeObserver(() => imgCue.value && layoutCue(imgCue.value))
      resizeObs.observe(videoEl.value)
    }
  } catch (e) {
    toastErr(e?.message || '图片字幕加载失败')
  }
}

const imgSrc = computed(() => {
  const sub = imageSub.value
  const c = imgCue.value
  if (!sub || !c) return ''
  return subtitleAssetUrl(ep.value.id, sub.index, c.f)
})

// ------------------------------------------------------------------
// 续播与进度上报
// ------------------------------------------------------------------

function applyResume() {
  if (seeked) return
  seeked = true
  const el = videoEl.value
  if (!el || !pendingResume) return

  const dur = Number(el.duration) || 0
  if (pendingResume <= 3) return
  if (dur > 0 && pendingResume >= dur * 0.95) return // 上次看到尾了，从头开始
  try {
    el.currentTime = pendingResume
    toast(`已定位到 ${fmtClock(pendingResume)}`)
  } catch {
    /* 元数据还没就绪，放弃这次定位 */
  }
}

function onLoadedMetadata() {
  if (useEngine.value) return
  applyResume()
  trackSyncLoop()
}

function onError() {
  if (useEngine.value) return // 引擎模式统一走引擎的 error 事件
  const e = videoEl.value?.error
  errMsg.value =
    e?.code === 4
      ? '浏览器无法解码这个文件。用下面的「复制直链」丢给 PotPlayer / VLC 播放。'
      : '视频加载失败，可能是文件已删除或权限不足。'
}

function report(force = false) {
  const v = videoEl.value
  if (!v || !ep.value) return
  const pos = Math.floor(v.currentTime || 0)
  const dur = Math.floor(v.duration || 0)
  if (!force && pos < 3) return
  if (!Number.isFinite(dur)) return
  api.post('/api/me/progress', {
    episode_id: ep.value.id,
    position_sec: pos,
    duration_sec: dur,
  }).catch(() => {})
}

function beacon() {
  const v = videoEl.value
  if (!v || !ep.value) return
  const dur = Math.floor(v.duration || 0)
  if (!Number.isFinite(dur) || dur <= 0) return
  const payload = JSON.stringify({
    episode_id: ep.value.id,
    position_sec: Math.floor(v.currentTime || 0),
    duration_sec: dur,
  })
  const token = getToken()
  navigator.sendBeacon(
    `/api/me/progress?token=${encodeURIComponent(token)}`,
    new Blob([payload], { type: 'application/json' })
  )
}

function onEnded() {
  report(true)
  if (data.value?.next) {
    router.push({ name: 'play', params: { id: data.value.next.id } })
  }
}

async function copyLink(withToken) {
  const u = new URL(streamUrl(ep.value.id, true), location.origin)
  if (!withToken) u.searchParams.delete('token')
  const text = u.toString()
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
    toast(withToken ? '已复制带令牌直链（有效期 7 天）' : '已复制直链（需自行追加令牌）')
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('复制下面的链接：', text)
  }
}

watch(() => route.params.id, load)

onMounted(async () => {
  await load()
  timer = setInterval(() => report(false), 10000)
  window.addEventListener('beforeunload', beacon)
  document.addEventListener('visibilitychange', onVisibility)
})

function onVisibility() {
  if (document.visibilityState === 'hidden') beacon()
}

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
  if (resizeObs) {
    resizeObs.disconnect()
    resizeObs = null
  }
  window.removeEventListener('beforeunload', beacon)
  document.removeEventListener('visibilitychange', onVisibility)
  report(true)
  teardown()
})
</script>

<template>
  <div class="wrap-wide page">
    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else-if="ep">
      <div class="between mb-14" style="gap: 12px; flex-wrap: wrap">
        <div class="grow" style="min-width: 240px">
          <router-link :to="{ name: 'anime', params: { id: anime.id } }" class="small">
            ← {{ title }}
          </router-link>
          <h1 class="mt-8">
            第 {{ epLabel(ep.ep_no) }} 集
            <span v-if="ep.resolution" class="tag tag-brand" style="vertical-align: 3px">{{ ep.resolution }}</span>
            <span
              v-if="ep.remote"
              class="tag tag-ok"
              style="vertical-align: 3px"
              title="本地已无副本，播放由对象存储直链提供"
            >远程</span>
          </h1>
          <div class="muted-3 tiny mt-8 break">{{ ep.title }}</div>
        </div>

        <div class="row-gap">
          <router-link
            v-if="data.prev"
            :to="{ name: 'play', params: { id: data.prev.id } }"
            class="btn btn-sm"
          >上一集</router-link>
          <router-link
            v-if="data.next"
            :to="{ name: 'play', params: { id: data.next.id } }"
            class="btn btn-sm"
          >下一集</router-link>
        </div>
      </div>

      <div class="row-gap" style="align-items: flex-start; gap: 18px; flex-wrap: wrap">
        <div class="grow" style="min-width: 320px">
          <div class="player-shell">
            <video
              ref="videoEl"
              :src="nativeSrc || undefined"
              controls
              playsinline
              preload="metadata"
              @loadedmetadata="onLoadedMetadata"
              @ended="onEnded"
              @error="onError"
            >
              <!-- 文本轨由服务端抽好的 WebVTT 直供；图片轨不走 <track>（见下方叠加层） -->
              <track
                v-for="(t, i) in textTracks"
                :key="t.index"
                kind="subtitles"
                :src="trackSrc(t.index)"
                :label="t.label || '字幕 ' + (t.index + 1)"
                :srclang="t.language || 'und'"
                :default="i === 0"
              />
            </video>

            <!-- 图片字幕叠加层：位图字幕原画，按 PGS 自带的时机与位置叠在画面上 -->
            <div class="sub-overlay">
              <img v-if="imgSrc" :src="imgSrc" :style="imgBox" alt="" />
            </div>

            <!-- 弹幕层：独立 canvas，与视频字节流无关（z-index 在字幕层之上） -->
            <DanmakuLayer
              ref="dmEl"
              :episode-id="ep.id"
              :get-video="() => videoEl"
              :enabled="dmOn"
              :opacity="dmOpacity"
              :scale="dmScale"
              :blocked="dmBlocked"
              @loaded="dmCount = $event"
              @fail="dmErr = $event"
            />
          </div>

          <!-- 弹幕工具条 -->
          <div class="dm-bar mt-14">
            <label class="dm-toggle">
              <input v-model="dmOn" type="checkbox" />
              <span>弹幕</span>
            </label>
            <span class="tiny muted-3">{{ dmCount }} 条</span>

            <input
              v-model="dmText"
              class="input dm-input"
              type="text"
              maxlength="120"
              placeholder="发一条弹幕…（回车发送）"
              @keydown.enter="sendDanmaku"
            />
            <button
              class="btn btn-sm btn-primary"
              :disabled="dmSending || !dmText.trim()"
              @click="sendDanmaku"
            >{{ dmSending ? '发送中' : '发送' }}</button>
            <button class="btn btn-sm" @click="dmCfgOpen = !dmCfgOpen">
              {{ dmCfgOpen ? '收起设置' : '弹幕设置' }}
            </button>
          </div>

          <div v-if="dmCfgOpen" class="dm-cfg mt-10">
            <div class="dm-rng">
              <label class="label">不透明度 {{ Math.round(dmOpacity * 100) }}%</label>
              <input v-model.number="dmOpacity" type="range" min="0.2" max="1" step="0.05" />
            </div>
            <div class="dm-rng">
              <label class="label">字号 {{ dmScale.toFixed(1) }}×</label>
              <input v-model.number="dmScale" type="range" min="0.7" max="1.6" step="0.1" />
            </div>
            <div class="dm-block">
              <label class="label">屏蔽词（每行一个，只在本机生效）</label>
              <textarea
                v-model="dmBlockText"
                class="input"
                rows="3"
                placeholder="剧透&#10;刷屏关键词"
              ></textarea>
              <div class="row-gap mt-8">
                <button class="btn btn-sm" @click="applyBlocked">应用</button>
                <span class="tiny muted-3">{{ dmBlocked.length }} 条生效中</span>
              </div>
            </div>
          </div>

          <div v-if="dmErr" class="alert alert-warn mt-10">{{ dmErr }}</div>

          <div v-if="stage" class="row-gap mt-14 tiny muted-3">
            <span class="spin spin-sm"></span>
            <span>{{ stage }}</span>
          </div>

          <div v-if="pipelineLabel || ep.remuxed || serverTracks.length" class="row-gap mt-10" style="flex-wrap: wrap; gap: 8px">
            <span v-if="pipelineLabel" class="tag">{{ pipelineLabel }}</span>
            <span v-if="pipeline && pipeline.segments" class="tag">{{ pipeline.segments }} 段</span>
            <span v-if="ep.remuxed" class="tag tag-brand">原生 MP4</span>
            <span v-if="serverTracks.length" class="tag tag-brand">{{ serverTracks.length }} 条字幕</span>
            <span v-if="plan && plan.audio_remux" class="tag tag-warn">音频需前端转码</span>
          </div>

          <div v-if="hint" class="alert alert-warn mt-14">{{ hint }}</div>
          <div v-if="errMsg" class="alert alert-err mt-14">{{ errMsg }}</div>
          <div v-if="subNote" class="alert alert-warn mt-14">{{ subNote }}</div>

          <div v-if="tracks.length > 1 || imageTracks.length" class="row-gap mt-14" style="gap: 8px; flex-wrap: wrap">
            <span class="tiny muted-3">字幕</span>
            <button
              v-for="t in tracks"
              :key="t.i"
              class="btn btn-sm"
              :class="{ 'btn-primary': t.i === activeTrack }"
              @click="pickTrack(t.i)"
            >{{ t.label }}</button>
            <button
              v-for="t in imageTracks"
              :key="'img' + t.index"
              class="btn btn-sm"
              :class="{ 'btn-primary': imageSub && imageSub.index === t.index }"
              @click="toggleImageSub(t)"
            >{{ t.label || '原画字幕' }}（图片）</button>
            <button
              v-if="imageSub"
              class="btn btn-sm"
              @click="clearImageSub"
            >关闭</button>
          </div>

          <div class="wrapflex mt-14">
            <button class="btn btn-sm" @click="copyLink(true)">复制播放直链</button>
            <a class="btn btn-sm" :href="dlHref">下载本集</a>
            <!-- 图片轨没有 VTT 可下，只在文本轨上给下载入口 -->
            <a
              v-for="t in textTracks"
              :key="'dl' + t.index"
              class="btn btn-sm"
              :href="trackSrc(t.index) + '&dl=1'"
            >字幕 {{ t.label || t.index + 1 }}</a>
            <span class="spacer"></span>
            <span class="tiny muted-3">
              {{ formatSize(ep.file_size) }}
              <template v-if="ep.subgroup"> · {{ ep.subgroup }}</template>
              <template v-if="ep.views"> · {{ ep.views }} 次播放</template>
            </span>
          </div>
        </div>

        <!-- 选集 -->
        <div style="width: 244px; flex: none; max-width: 100%">
          <div class="card card-tight">
            <div class="between mb-8">
              <h3>选集</h3>
              <span class="tiny muted-3">{{ data.siblings.length }} 集</span>
            </div>
            <div style="max-height: 62vh; overflow: auto">
              <router-link
                v-for="s in data.siblings"
                :key="s.id"
                :to="{ name: 'play', params: { id: s.id } }"
                class="ep"
                :class="{ watched: s.id === ep.id }"
                style="margin-bottom: 5px"
              >
                <span class="dot"></span>
                <span class="no">{{ epLabel(s.ep_no) }}</span>
                <span class="grow ellip tiny muted-3">{{ s.resolution || '' }}</span>
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-else class="card empty">
      <div class="big">剧集不存在</div>
    </div>
  </div>
</template>

<style scoped>
/* 叠加层要相对画面定位，所以播放器盒子必须是定位上下文 */
.player-shell {
  position: relative;
}

/*
 * object-fit 的初始值是 fill（拉伸填满），一旦CSS给视频盒子的比例与片源不一致
 * （比如 max-height 生效时）画面就被拉变形。改成 contain 之后画面居中留黑边，
 * 叠加层的坐标换算也才有确定的依据（见 layoutCue）。
 */
.player-shell video {
  object-fit: contain;
}

/*
 * 覆盖整个播放器盒子，但不吃鼠标事件 —— 否则会挡住控制条。
 * 图片自己是 absolute，位置与尺寸由 PGS 给的原坐标换算得到。
 * z-index 1：弹幕层在它之上（弹幕是"飘过"的内容，压在字幕上也正常）。
 */
.sub-overlay {
  position: absolute;
  inset: 0;
  z-index: 1;
  pointer-events: none;
  overflow: hidden;
}

.sub-overlay img {
  position: absolute;
  display: block;
  /* 位图字幕自带描边与阴影，不需要额外加工；只保证缩放后不糊得太厉害 */
  image-rendering: auto;
}

/* ---------------- 弹幕工具条 ---------------- */
.dm-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dm-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
}

.dm-input {
  flex: 1 1 200px;
  min-width: 160px;
  max-width: 460px;
}

.dm-cfg {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
  align-items: flex-start;
}

.dm-rng {
  width: 200px;
}

.dm-rng input[type='range'] {
  width: 100%;
  margin-top: 6px;
}

.dm-block {
  flex: 1 1 260px;
  min-width: 240px;
}
</style>
