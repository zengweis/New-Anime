import { reactive } from 'vue'
import { api } from '../api'

/**
 * 站点公开配置（来自 /api/auth/config）。
 * 全站单例，登录/注册页与顶栏共用，避免各自重复请求。
 */
export const site = reactive({
  name: '看番',
  tagline: '私人追番库',
  registerEnabled: true,
  needInvite: false,
  needVerify: false,
  needApprove: false,
  ready: false,
})

let loading = null

export function loadSite(force = false) {
  if (loading && !force) return loading

  loading = (async () => {
    try {
      const c = await api.get('/api/auth/config')
      site.name = c.site_name || site.name
      site.registerEnabled = !!c.enabled
      site.needInvite = !!c.need_invite
      site.needVerify = !!c.need_verify
      site.needApprove = !!c.need_approve
    } catch {
      /* 拉不到就用默认值，不阻塞渲染 */
    }
    site.ready = true
    if (typeof document !== 'undefined') document.title = site.name
    return site
  })()

  return loading
}
