import { reactive } from 'vue'
import { api, getToken, setToken } from '../api'

export const auth = reactive({
  user: null,
  ready: false,
})

let loading = null

/** 载入当前用户（并发调用只发一次请求） */
export function loadUser(force = false) {
  if (loading && !force) return loading
  loading = (async () => {
    if (!getToken()) {
      auth.user = null
      auth.ready = true
      return null
    }
    try {
      auth.user = await api.get('/api/auth/me')
    } catch {
      auth.user = null
    }
    auth.ready = true
    return auth.user
  })()
  return loading
}

export async function login(account, password) {
  const d = await api.post('/api/auth/login', { account, password })
  setToken(d.token)
  auth.user = d.user
  auth.ready = true
  return d
}

export function logout() {
  setToken('')
  auth.user = null
}

export function isAdmin() {
  return auth.user?.role === 'admin'
}
