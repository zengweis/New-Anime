<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { toast, toastErr } from '../store/ui'

const route = useRoute()

const state = ref('loading')
const msg = ref('')
const email = ref('')
const resending = ref(false)

onMounted(async () => {
  const token = String(route.query.token || '')
  if (!token) {
    state.value = 'fail'
    msg.value = '链接缺少验证令牌，请从邮件里重新点击'
    return
  }
  try {
    await api.post('/api/auth/verify-email', { token })
    state.value = 'ok'
    msg.value = '邮箱验证成功，现在可以登录了'
  } catch (e) {
    state.value = 'fail'
    msg.value = e.message
  }
})

async function resend() {
  if (!email.value.trim()) {
    toast('请先填写注册邮箱', 'err')
    return
  }
  resending.value = true
  try {
    const r = await api.post('/api/auth/resend-verify', { email: email.value.trim() })
    toast(r?.msg || '若邮箱未验证，验证信已重新发送')
  } catch (e) {
    toastErr(e)
  }
  resending.value = false
}
</script>

<template>
  <div class="auth-wrap">
    <div class="auth-box">
      <div class="card">
        <div v-if="state === 'loading'" class="loading"><span class="spin"></span> 正在验证</div>

        <div v-else-if="state === 'ok'" class="empty">
          <div class="big">{{ msg }}</div>
          <router-link :to="{ name: 'login' }" class="btn btn-primary mt-14">去登录</router-link>
        </div>

        <template v-else>
          <div class="empty" style="padding-bottom: 20px">
            <div class="big">验证失败</div>
            <div>{{ msg }}</div>
          </div>

          <div class="divider"></div>

          <div class="stack-s">
            <label class="label">重新发送验证邮件</label>
            <div class="row-gap">
              <input v-model="email" type="email" class="input" placeholder="注册邮箱" />
              <button class="btn" :disabled="resending" @click="resend">
                {{ resending ? '发送中' : '发送' }}
              </button>
            </div>
          </div>
        </template>
      </div>

      <div class="auth-foot"><router-link :to="{ name: 'login' }">返回登录</router-link></div>
    </div>
  </div>
</template>
