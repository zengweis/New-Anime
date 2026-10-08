import { reactive } from 'vue'

export const ui = reactive({
  toasts: [],
  busy: false,
})

let seq = 0

export function toast(msg, type = 'ok', ms = 2600) {
  const id = ++seq
  ui.toasts.push({ id, msg, type })
  setTimeout(() => {
    const i = ui.toasts.findIndex((t) => t.id === id)
    if (i >= 0) ui.toasts.splice(i, 1)
  }, ms)
}

export function toastErr(e) {
  toast(e?.message || String(e) || '操作失败', 'err', 3600)
}

/** 统一的错误提示包装 */
export async function guard(fn, okMsg) {
  try {
    const r = await fn()
    if (okMsg) toast(okMsg)
    return r
  } catch (e) {
    toastErr(e)
    return null
  }
}
