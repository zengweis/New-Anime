<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'
import { auth } from '../../store/auth'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 30
const busy = ref('')

const filter = reactive({ status: -1, keyword: '' })

const STATUS = [
  { v: -1, cn: '全部' },
  { v: 1, cn: '正常' },
  { v: 0, cn: '待验证邮箱' },
  { v: 2, cn: '待审核' },
  { v: 9, cn: '已禁用' },
]

const STATUS_TAG = { 0: 'tag-warn', 1: 'tag-ok', 2: 'tag-brand', 9: 'tag-mute' }

const show = ref(false)
const form = ref({})
const formErr = ref('')

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/users', {
      page: page.value,
      per_page: perPage,
      status: filter.status,
      keyword: filter.keyword.trim(),
    })
    list.value = d.list || []
    total.value = d.total || 0
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

function search() {
  page.value = 1
  load()
}

function goPage(p) {
  const max = Math.max(1, Math.ceil(total.value / perPage))
  page.value = Math.min(max, Math.max(1, p))
  load()
}

function openNew() {
  form.value = { username: '', email: '', password: '', nickname: '', role: 'user' }
  formErr.value = ''
  show.value = true
}

async function createUser() {
  formErr.value = ''
  const f = form.value
  if (!f.username.trim()) return (formErr.value = '请填写用户名')
  if (!/^[\u4e00-\u9fa5A-Za-z][\u4e00-\u9fa5A-Za-z0-9_-]{1,19}$/.test(f.username.trim()))
    return (formErr.value = '用户名 2-20 位，中文/字母开头')
  if (!/^\S+@\S+\.\S+$/.test(f.email.trim())) return (formErr.value = '邮箱格式不正确')
  if ((f.password || '').length < 8) return (formErr.value = '密码至少 8 位')

  busy.value = 'create'
  try {
    await api.post('/api/admin/users', {
      username: f.username.trim(),
      email: f.email.trim().toLowerCase(),
      password: f.password,
      nickname: f.nickname.trim(),
      role: f.role,
    })
    toast('账号已创建')
    show.value = false
    await load()
  } catch (e) {
    formErr.value = e.message || '创建失败'
  }
  busy.value = ''
}

async function setStatus(u, status) {
  const label = { 0: '置为待验证', 1: '放行', 2: '置为待审核', 9: '禁用' }[status]
  if (!window.confirm(`确定将「${u.username}」${label}？`)) return
  busy.value = 'st' + u.id
  try {
    const r = await api.post(`/api/admin/users/${u.id}/status`, { status })
    toast(r?.msg || '已更新')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function setRole(u, role) {
  busy.value = 'role' + u.id
  try {
    await api.post(`/api/admin/users/${u.id}/role`, { role })
    toast(role === 'admin' ? '已设为管理员' : '已降为普通用户')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function del(u) {
  if (!window.confirm(`删除用户「${u.username}」？其收藏与观看记录会一并删除，且不可恢复。`)) return
  busy.value = 'del' + u.id
  try {
    await api.del(`/api/admin/users/${u.id}`)
    toast('已删除')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>用户</h1>
        <div class="small muted-3 mt-8">
          共 {{ total }} 个账号。状态：0 待验证邮箱 · 1 正常 · 2 待审核 · 9 已禁用。
        </div>
      </div>
      <button class="btn btn-primary btn-sm" @click="openNew">新建账号</button>
    </div>

    <div class="card card-tight mb-14">
      <div class="row-gap" style="flex-wrap: wrap">
        <div class="seg">
          <button
            v-for="s in STATUS"
            :key="s.v"
            class="seg-item"
            :class="{ on: filter.status === s.v }"
            @click="filter.status = s.v; search()"
          >{{ s.cn }}</button>
        </div>
        <input
          v-model="filter.keyword"
          class="input"
          style="max-width: 240px"
          placeholder="用户名 / 邮箱 / 昵称"
          @keyup.enter="search"
        />
        <button class="btn btn-sm" @click="search">搜索</button>
      </div>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 56px">ID</th>
              <th>用户名</th>
              <th>邮箱</th>
              <th style="width: 96px">角色</th>
              <th style="width: 104px">状态</th>
              <th style="width: 132px">最后登录</th>
              <th style="width: 132px">注册时间</th>
              <th style="width: 214px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in list" :key="u.id">
              <td class="muted-3">{{ u.id }}</td>
              <td class="nowrap">
                <span class="strong">{{ u.username }}</span>
                <span v-if="auth.user?.id === u.id" class="tag tag-brand ml-8">我</span>
              </td>
              <td class="tiny muted break" style="max-width: 220px">{{ u.email }}</td>
              <td>
                <span class="tag" :class="u.role === 'admin' ? 'tag-brand' : 'tag-mute'">
                  {{ u.role === 'admin' ? '管理员' : '用户' }}
                </span>
              </td>
              <td>
                <span class="tag" :class="STATUS_TAG[u.status]">{{ u.status_cn }}</span>
              </td>
              <td class="nowrap muted-3 small">{{ formatTime(u.last_login) }}</td>
              <td class="nowrap muted-3 small">{{ formatTime(u.created_at) }}</td>
              <td class="right nowrap">
                <button
                  v-if="auth.user?.id !== u.id && u.status !== 1"
                  class="btn btn-sm"
                  :disabled="!!busy"
                  @click="setStatus(u, 1)"
                >放行</button>
                <button
                  v-if="auth.user?.id !== u.id && u.status !== 9"
                  class="btn btn-sm btn-ghost"
                  :disabled="!!busy"
                  @click="setStatus(u, 9)"
                >禁用</button>
                <button
                  v-if="auth.user?.id !== u.id"
                  class="btn btn-sm btn-ghost"
                  :disabled="!!busy"
                  @click="setRole(u, u.role === 'admin' ? 'user' : 'admin')"
                >{{ u.role === 'admin' ? '降权' : '升管' }}</button>
                <button
                  v-if="auth.user?.id !== u.id"
                  class="btn btn-sm btn-danger"
                  :disabled="!!busy"
                  @click="del(u)"
                >删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">没有匹配的用户</div>
        <div>换个筛选条件试试</div>
      </div>

      <div v-if="total > perPage" class="pager">
        <button class="btn btn-sm" :disabled="page <= 1" @click="goPage(page - 1)">上一页</button>
        <span class="small muted-3">{{ page }} / {{ Math.max(1, Math.ceil(total / perPage)) }}</span>
        <button class="btn btn-sm" :disabled="page >= Math.ceil(total / perPage)" @click="goPage(page + 1)">下一页</button>
      </div>
    </div>

    <div v-if="show" class="mask" @click.self="show = false">
      <div class="modal">
        <div class="modal-head">
          <h3>新建账号</h3>
          <button class="btn btn-sm btn-ghost" @click="show = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="stack">
            <div class="field">
              <label class="label">用户名</label>
              <input v-model="form.username" class="input" placeholder="2-20 位，中文/字母开头" />
            </div>
            <div class="field">
              <label class="label">邮箱</label>
              <input v-model="form.email" class="input" placeholder="用于登录与找回密码" />
            </div>
            <div class="field">
              <label class="label">初始密码</label>
              <input v-model="form.password" class="input" type="text" placeholder="至少 8 位，含字母和数字" />
              <span class="tiny muted-3">创建后请自行转告用户，系统不会展示二次</span>
            </div>
            <div class="form-grid">
              <div class="field">
                <label class="label">昵称（可选）</label>
                <input v-model="form.nickname" class="input" placeholder="默认同用户名" />
              </div>
              <div class="field">
                <label class="label">角色</label>
                <select v-model="form.role" class="select">
                  <option value="user">普通用户</option>
                  <option value="admin">管理员</option>
                </select>
              </div>
            </div>
            <div v-if="formErr" class="alert alert-err">{{ formErr }}</div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="show = false">取消</button>
          <button class="btn btn-primary" :disabled="busy === 'create'" @click="createUser">
            {{ busy === 'create' ? '创建中…' : '创建' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
