import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { onUnauthorized } from './api'
import { auth } from './store/auth'
import { loadSite } from './store/site'
import './assets/main.css'

createApp(App).use(router).mount('#app')

// 站点名/注册开关，全站只拉一次
loadSite()

// 令牌失效时统一回登录页
onUnauthorized(() => {
  auth.user = null
  auth.ready = true
  const cur = router.currentRoute.value
  if (cur.meta.requiresAuth || cur.meta.requiresAdmin) {
    router.replace({ name: 'login', query: { redirect: cur.fullPath } })
  }
})
