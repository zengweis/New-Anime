<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login } from '../store/auth'
import { site, loadSite } from '../store/site'
import { toast, toastErr } from '../store/ui'

const route = useRoute()
const router = useRouter()

const account = ref('')
const password = ref('')
const busy = ref(false)
const siteName = computed(() => site.name)

onMounted(() => loadSite())

async function submit() {
  if (busy.value || !account.value.trim() || !password.value) return
  busy.value = true
  try {
    const d = await login(account.value.trim(), password.value)
    toast('欢迎回来，' + (d.user.nickname || d.user.username))
    const to = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    router.replace(to)
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}
</script>

<template>
  <div class="auth-wrap">
    <div class="auth-box">
      <div class="auth-brand">
        <div class="n">{{ siteName }}</div>
        <div class="d">登录后开始追番</div>
      </div>

      <div class="card">
        <form class="stack" @submit.prevent="submit">
          <div class="field">
            <label class="label">用户名或邮箱</label>
            <input v-model="account" class="input" autocomplete="username" autofocus />
          </div>
          <div class="field">
            <label class="label">密码</label>
            <input v-model="password" type="password" class="input" autocomplete="current-password" />
          </div>
          <button class="btn btn-primary btn-lg btn-block" :disabled="busy">
            <span v-if="busy" class="spin"></span>
            {{ busy ? '登录中' : '登录' }}
          </button>
        </form>

        <div class="between mt-14">
          <router-link :to="{ name: 'forgot' }" class="small">忘记密码？</router-link>
          <router-link :to="{ name: 'register' }" class="small">注册新账号</router-link>
        </div>
      </div>

      <div class="auth-foot">
        <router-link to="/">← 返回首页</router-link>
      </div>
    </div>
  </div>
</template>
