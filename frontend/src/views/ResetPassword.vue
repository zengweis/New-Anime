<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { toast, toastErr } from '../store/ui'

const route = useRoute()

const token = ref('')
const password = ref('')
const password2 = ref('')
const busy = ref(false)
const ok = ref(false)

onMounted(() => {
  token.value = String(route.query.token || '')
})

async function submit() {
  if (busy.value) return
  if (!token.value) {
    toast('链接缺少令牌', 'err')
    return
  }
  if (password.value !== password2.value) {
    toast('两次输入的密码不一致', 'err')
    return
  }
  busy.value = true
  try {
    await api.post('/api/auth/reset-password', { token: token.value, password: password.value })
    ok.value = true
    toast('密码已重置')
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
        <div class="n">设置新密码</div>
        <div class="d">至少 8 位，需同时包含字母和数字</div>
      </div>

      <div class="card">
        <div v-if="ok" class="empty">
          <div class="big">密码已重置</div>
          <div>现在可以用新密码登录了</div>
          <router-link :to="{ name: 'login' }" class="btn btn-primary mt-14">去登录</router-link>
        </div>

        <form v-else class="stack" @submit.prevent="submit">
          <div class="field">
            <label class="label">新密码</label>
            <input v-model="password" type="password" class="input" autocomplete="new-password" autofocus />
          </div>
          <div class="field">
            <label class="label">确认新密码</label>
            <input v-model="password2" type="password" class="input" autocomplete="new-password" />
          </div>
          <button class="btn btn-primary btn-lg btn-block" :disabled="busy">
            <span v-if="busy" class="spin"></span>
            {{ busy ? '提交中' : '确认修改' }}
          </button>
        </form>
      </div>

      <div class="auth-foot"><router-link :to="{ name: 'login' }">返回登录</router-link></div>
    </div>
  </div>
</template>
