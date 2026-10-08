<script setup>
import { ref } from 'vue'
import { api } from '../api'
import { toast, toastErr } from '../store/ui'

const email = ref('')
const busy = ref(false)
const sent = ref(false)

async function submit() {
  if (busy.value || !email.value.trim()) return
  busy.value = true
  try {
    const r = await api.post('/api/auth/forgot-password', { email: email.value.trim() })
    sent.value = true
    toast(r?.msg || '若该邮箱已注册，重置链接已发送')
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
        <div class="n">找回密码</div>
        <div class="d">输入注册邮箱，我们会发送重置链接</div>
      </div>

      <div class="card">
        <div v-if="sent" class="empty">
          <div class="big">邮件已发送</div>
          <div>请查收邮件（含垃圾箱），链接 1 小时内有效</div>
          <router-link :to="{ name: 'login' }" class="btn mt-14">返回登录</router-link>
        </div>

        <form v-else class="stack" @submit.prevent="submit">
          <div class="field">
            <label class="label">注册邮箱</label>
            <input v-model="email" type="email" class="input" autofocus />
          </div>
          <button class="btn btn-primary btn-lg btn-block" :disabled="busy">
            <span v-if="busy" class="spin"></span>
            {{ busy ? '发送中' : '发送重置链接' }}
          </button>
        </form>
      </div>

      <div class="auth-foot"><router-link :to="{ name: 'login' }">返回登录</router-link></div>
    </div>
  </div>
</template>
