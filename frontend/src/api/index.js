const TOKEN_KEY = 'anime_token'

export function getToken() {
  try {
    return localStorage.getItem(TOKEN_KEY) || ''
  } catch {
    return ''
  }
}

export function setToken(t) {
  try {
    if (t) localStorage.setItem(TOKEN_KEY, t)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* 隐私模式下忽略 */
  }
}

export class ApiError extends Error {
  constructor(msg, code) {
    super(msg)
    this.name = 'ApiError'
    this.code = code
  }
}

let unauthorizedHandler = null
export function onUnauthorized(fn) {
  unauthorizedHandler = fn
}

async function request(method, path, opts = {}) {
  const { body, query } = opts

  const url = new URL(path, location.origin)
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v)
    }
  }

  const headers = {}
  const token = getToken()
  if (token) headers.Authorization = 'Bearer ' + token

  let payload
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let res
  try {
    res = await fetch(url.toString(), { method, headers, body: payload })
  } catch {
    throw new ApiError('网络请求失败，请检查网络连接')
  }

  if (res.status === 401) {
    setToken('')
    if (unauthorizedHandler) unauthorizedHandler()
    throw new ApiError('登录已过期，请重新登录', 401)
  }
  if (res.status === 403) {
    throw new ApiError('没有访问权限', 403)
  }

  let data
  try {
    data = await res.json()
  } catch {
    throw new ApiError(`服务器响应异常 (HTTP ${res.status})`)
  }

  if (data.code !== 0) {
    throw new ApiError(data.msg || '请求失败', data.code)
  }
  return data.data
}

export const api = {
  get: (path, query) => request('GET', path, { query }),
  post: (path, body, query) => request('POST', path, { body: body ?? {}, query }),
  put: (path, body) => request('PUT', path, { body: body ?? {} }),
  del: (path, query) => request('DELETE', path, { query }),
}

/** 视频流地址（token 走 query，方便外部播放器直接打开） */
export function streamUrl(episodeId, download = false) {
  const t = getToken()
  return `/stream/${episodeId}?token=${encodeURIComponent(t)}${download ? '&dl=1' : ''}`
}

/**
 * 字幕地址。`<track src>` 由浏览器自发请求、带不了 Authorization 头，
 * 所以令牌同样只能走 query —— 与 streamUrl 一致。
 */
export function subtitleUrl(episodeId, trackIndex) {
  const t = getToken()
  return `/subtitle/${episodeId}/${trackIndex}.vtt?token=${encodeURIComponent(t)}`
}

/**
 * 图片字幕的地址（manifest.json 或某一屏图片）。
 * 同样是浏览器自发的 <img>/fetch 请求，令牌只能走 query。
 */
export function subtitleAssetUrl(episodeId, trackIndex, file) {
  const t = getToken()
  return `/subtitle/${episodeId}/${trackIndex}/${file}?token=${encodeURIComponent(t)}`
}

export function formatSize(bytes) {
  const b = Number(bytes) || 0
  if (b <= 0) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)))
  const v = b / Math.pow(1024, i)
  return (v >= 100 || i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + u[i]
}

export function formatDuration(sec) {
  const s = Math.max(0, Math.floor(Number(sec) || 0))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const ss = s % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(ss)}` : `${m}:${pad(ss)}`
}

export function formatTime(str) {
  if (!str) return '-'
  const d = new Date(String(str).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return String(str)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return '刚刚'
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  if (diff < 86400 * 7) return `${Math.floor(diff / 86400)} 天前`
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

export function epLabel(epNo) {
  const n = Number(epNo)
  if (!n || n <= 0) return '合集'
  return Number.isInteger(n) ? String(n).padStart(2, '0') : n.toFixed(1)
}

export const SEASON_CN = { 1: '春', 2: '夏', 3: '秋', 4: '冬', 0: '冬' }
