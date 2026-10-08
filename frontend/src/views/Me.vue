<script setup>
import { ref, onMounted } from 'vue'
import { api, formatDuration, epLabel, formatTime } from '../api'
import { auth } from '../store/auth'
import { toast, toastErr } from '../store/ui'
import AnimeCard from '../components/AnimeCard.vue'

const tab = ref('fav')

const favs = ref([])
const history = ref([])
const stats = ref({ favorites: 0, watched: 0, finished: 0 })
const loading = ref(true)

const pwd = ref({ old_password: '', new_password: '', new_password2: '' })
const changing = ref(false)

async function loadAll() {
  loading.value = true
  const tasks = [
    api.get('/api/me/favorites').then((d) => (favs.value = d.list || [])).catch(() => {}),
    api.get('/api/me/history', { per_page: 50 }).then((d) => (history.value = d.list || [])).catch(() => {}),
    api.get('/api/me/stats').then((d) => (stats.value = d)).catch(() => {}),
  ]
  await Promise.all(tasks)
  loading.value = false
}

async function changePwd() {
  if (changing.value) return
  if (pwd.value.new_password !== pwd.value.new_password2) {
    toast('两次输入的新密码不一致', 'err')
    return
  }
  changing.value = true
  try {
    await api.post('/api/auth/change-password', {
      old_password: pwd.value.old_password,
      new_password: pwd.value.new_password,
    })
    toast('密码已修改')
    pwd.value = { old_password: '', new_password: '', new_password2: '' }
  } catch (e) {
    toastErr(e)
  }
  changing.value = false
}

onMounted(loadAll)
</script>

<template>
  <div class="wrap page">
    <div class="between mb-14" style="flex-wrap: wrap; gap: 10px">
      <h1>我的</h1>
      <div class="row-gap small muted-3">
        <span>{{ auth.user?.username }}</span>
        <span>·</span>
        <span>{{ auth.user?.email }}</span>
      </div>
    </div>

    <div class="stat-grid mb-14">
      <div class="stat">
        <div class="k">追番</div>
        <div class="v">{{ stats.favorites }}<small>部</small></div>
      </div>
      <div class="stat">
        <div class="k">看过</div>
        <div class="v">{{ stats.watched }}<small>集</small></div>
      </div>
      <div class="stat">
        <div class="k">看完</div>
        <div class="v">{{ stats.finished }}<small>集</small></div>
      </div>
    </div>

    <div class="wrapflex mb-14">
      <button class="btn btn-sm" :class="{ 'btn-primary': tab === 'fav' }" @click="tab = 'fav'">我的追番</button>
      <button class="btn btn-sm" :class="{ 'btn-primary': tab === 'history' }" @click="tab = 'history'">观看历史</button>
      <button class="btn btn-sm" :class="{ 'btn-primary': tab === 'account' }" @click="tab = 'account'">账号安全</button>
      <router-link :to="{ name: 'wish' }" class="btn btn-sm">许愿墙</router-link>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else>
      <!-- 追番 -->
      <section v-if="tab === 'fav'">
        <div v-if="favs.length" class="grid">
          <AnimeCard v-for="a in favs" :key="a.id" :anime="a" show-progress />
        </div>
        <div v-else class="card empty">
          <div class="big">还没有追番</div>
          <div>去番剧库挑几部，点「追番」就会出现在这里</div>
          <router-link :to="{ name: 'browse' }" class="btn btn-primary mt-14">去逛逛</router-link>
        </div>
      </section>

      <!-- 历史 -->
      <section v-else-if="tab === 'history'">
        <div v-if="history.length" class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>番剧</th>
                <th>集数</th>
                <th>进度</th>
                <th>时间</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="h in history" :key="h.episode_id">
                <td>
                  <router-link :to="{ name: 'anime', params: { id: h.anime_id } }">
                    {{ h.title_cn || h.title }}
                  </router-link>
                </td>
                <td class="nowrap">第 {{ epLabel(h.ep_no) }} 集</td>
                <td class="nowrap">
                  <span v-if="h.finished" class="tag tag-ok">已看完</span>
                  <span v-else class="small muted">
                    {{ formatDuration(h.position_sec) }} / {{ formatDuration(h.duration_sec) }}
                    <span class="muted-3">（{{ h.percent }}%）</span>
                  </span>
                </td>
                <td class="nowrap muted-3 small">{{ formatTime(h.updated_at) }}</td>
                <td class="right">
                  <router-link :to="{ name: 'play', params: { id: h.episode_id } }" class="btn btn-sm">
                    {{ h.finished ? '重看' : '继续' }}
                  </router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="card empty">
          <div class="big">还没有观看记录</div>
        </div>
      </section>

      <!-- 账号 -->
      <section v-else>
        <div class="card" style="max-width: 460px">
          <div class="card-head"><h3>修改密码</h3></div>
          <form class="stack" @submit.prevent="changePwd">
            <div class="field">
              <label class="label">当前密码</label>
              <input v-model="pwd.old_password" type="password" class="input" autocomplete="current-password" />
            </div>
            <div class="field">
              <label class="label">新密码</label>
              <input v-model="pwd.new_password" type="password" class="input" placeholder="至少 8 位，含字母和数字" autocomplete="new-password" />
            </div>
            <div class="field">
              <label class="label">确认新密码</label>
              <input v-model="pwd.new_password2" type="password" class="input" autocomplete="new-password" />
            </div>
            <button class="btn btn-primary" :disabled="changing">
              {{ changing ? '提交中' : '修改密码' }}
            </button>
          </form>

          <div class="divider"></div>

          <div class="stack-s">
            <div class="small muted">账号信息</div>
            <div class="small">用户名：{{ auth.user?.username }}</div>
            <div class="small">邮箱：{{ auth.user?.email }}</div>
            <div class="small">角色：{{ auth.user?.role === 'admin' ? '管理员' : '普通用户' }}</div>
            <div class="small muted-3">注册于 {{ formatTime(auth.user?.created_at) }}</div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
