<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import { site, loadSite } from '../store/site'
import { toast, toastErr } from '../store/ui'

const router = useRouter()

const loading = ref(true)
const busy = ref(false)
const done = ref(false)
const doneMsg = ref('')

const form = ref({ username: '', email: '', password: '', password2: '', invite_code: '' })

const cfg = computed(() => ({
  enabled: site.registerEnabled,
  need_invite: site.needInvite,
  need_verify: site.needVerify,
  need_approve: site.needApprove,
}))
const siteName = computed(() => site.name)

onMounted(async () => {
  await loadSite()
  loading.value = false
})

async function submit() {
  if (busy.value) return
  if (form.value.password !== form.value.password2) {
    toast('两次输入的密码不一致', 'err')
    return
  }
  busy.value = true
  try {
    const d = await api.post('/api/auth/register', {
      username: form.value.username,
      email: form.value.email,
      password: form.value.password,
      invite_code: form.value.invite_code,
    })
    doneMsg.value = d.msg || '注册成功'
    done.value = true
    if (!d.need_verify && !d.need_approve) {
      toast('注册成功，正在跳转登录')
      setTimeout(() => router.replace({ name: 'login' }), 1400)
    }
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
        <div class="d">注册账号</div>
      </div>

      <div v-if="loading" class="card loading"><span class="spin"></span> 加载中</div>

      <div v-else-if="!cfg.enabled" class="card">
        <div class="empty">
          <div class="big">本站已关闭注册</div>
          <div>请联系管理员开通账号</div>
        </div>
      </div>

      <div v-else-if="done" class="card">
        <div class="empty">
          <div class="big">{{ doneMsg }}</div>
          <div v-if="cfg.need_verify">请前往邮箱点击验证链接，完成后再登录</div>
          <div v-else-if="cfg.need_approve">账号需要管理员审核通过后才能登录</div>
          <router-link :to="{ name: 'login' }" class="btn btn-primary mt-14">去登录</router-link>
        </div>
      </div>

      <div v-else class="card">
        <form class="stack" @submit.prevent="submit">
          <div class="field">
            <label class="label">用户名<span class="req">*</span></label>
            <input v-model="form.username" class="input" placeholder="2-20 位，中文或字母开头" autocomplete="username" />
          </div>

          <div class="field">
            <label class="label">邮箱<span class="req">*</span></label>
            <input v-model="form.email" type="email" class="input" placeholder="用于验证和找回密码" autocomplete="email" />
          </div>

          <div class="field">
            <label class="label">密码<span class="req">*</span></label>
            <input v-model="form.password" type="password" class="input" placeholder="至少 8 位，含字母和数字" autocomplete="new-password" />
          </div>

          <div class="field">
            <label class="label">确认密码<span class="req">*</span></label>
            <input v-model="form.password2" type="password" class="input" autocomplete="new-password" />
          </div>

          <div v-if="cfg.need_invite" class="field">
            <label class="label">邀请码<span class="req">*</span></label>
            <input v-model="form.invite_code" class="input input-mono" placeholder="AN-XXXX-XXXX" />
            <span class="tiny muted-3">本站为邀请制，请向管理员索取</span>
          </div>

          <div v-if="cfg.need_verify" class="alert mt-8">
            注册后需要前往邮箱点击验证链接才能登录
          </div>

          <button class="btn btn-primary btn-lg btn-block" :disabled="busy">
            <span v-if="busy" class="spin"></span>
            {{ busy ? '提交中' : '注册' }}
          </button>
        </form>
      </div>

      <div class="auth-foot">
        已有账号？<router-link :to="{ name: 'login' }">去登录</router-link>
      </div>
    </div>
  </div>
</template>
