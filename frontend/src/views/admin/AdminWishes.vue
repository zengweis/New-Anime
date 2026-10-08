<script setup>
import { ref, onMounted } from 'vue'
import { api, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const busyId = ref(0)
const dailyLimit = ref(5)
const savingLimit = ref(false)

// 修改状态弹窗
const resolving = ref(null)
const resolveStatus = ref(0)
const resolveNote = ref('')

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/wishes')
    list.value = d.list || []
    dailyLimit.value = d.daily_limit || 5
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

async function saveLimit() {
  savingLimit.value = true
  try {
    const d = await api.post('/api/admin/wishes/limit', { limit: Number(dailyLimit.value) || 5 })
    dailyLimit.value = d?.daily_limit ?? dailyLimit.value
    toast(d?.msg || '每日上限已更新')
  } catch (e) {
    toastErr(e)
  }
  savingLimit.value = false
}

const statusCn = (s) => ({ 0: '许愿中', 1: '已实现', 2: '实现中', 3: '暂无计划' }[s] || '许愿中')

function openResolve(w) {
  resolving.value = w
  resolveStatus.value = Number(w.status)
  resolveNote.value = w.result_note || ''
}

async function confirmResolve() {
  const w = resolving.value
  if (!w) return
  const note = resolveNote.value.trim()
  if (Number(resolveStatus.value) === Number(w.status) && note === (w.result_note || '')) {
    toast('状态和备注都没有变化', 'err')
    return
  }
  busyId.value = w.id
  try {
    await api.post(`/api/admin/wishes/${w.id}/resolve`, { status: Number(resolveStatus.value), result_note: note || null })
    toast('状态已更新')
    resolving.value = null
    await load()
  } catch (e) {
    toastErr(e)
  }
  busyId.value = 0
}

async function del(w) {
  if (!window.confirm(`删除「${w.anime_name}」的许愿？`)) return
  busyId.value = w.id
  try {
    await api.del(`/api/admin/wishes/${w.id}`)
    toast('已删除')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busyId.value = 0
}

const statusClass = (s) => (s === 1 ? 'tag-ok' : s === 2 ? 'tag-warn' : s === 3 ? 'tag-mute' : 'tag-brand')

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14" style="flex-wrap: wrap; gap: 12px">
      <div>
        <h1>许愿墙</h1>
        <div class="small muted-3 mt-8">用户许愿想看的动漫，标记结果后用户能在许愿墙看到。</div>
      </div>
      <div class="row-gap">
        <span class="small muted-3 nowrap">每账户每日上限</span>
        <input v-model.number="dailyLimit" class="input input-sm" type="number" min="1" max="100" style="width: 70px" />
        <button class="btn btn-sm" :disabled="savingLimit" @click="saveLimit">
          {{ savingLimit ? '保存中…' : '保存' }}
        </button>
        <button class="btn btn-sm btn-ghost" @click="load">刷新</button>
      </div>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 46px">ID</th>
              <th>动漫名称</th>
              <th style="width: 120px">许愿人</th>
              <th>备注</th>
              <th style="width: 90px">状态</th>
              <th style="width: 150px">时间</th>
              <th style="width: 190px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in list" :key="w.id">
              <td class="muted-3">{{ w.id }}</td>
              <td class="bold">{{ w.anime_name }}</td>
              <td class="nowrap small">{{ w.nickname || w.username }}</td>
              <td class="small muted-3 ellip" style="max-width: 240px" :title="w.note">{{ w.note || '—' }}</td>
              <td>
                <span class="tag" :class="statusClass(w.status)">{{ w.status_cn }}</span>
                <div v-if="w.result_note" class="tiny muted-3 mt-8">{{ w.result_note }}</div>
              </td>
              <td class="nowrap muted-3 small">{{ formatTime(w.created_at) }}</td>
              <td class="right nowrap">
                <button class="btn btn-sm" :disabled="busyId === w.id" @click="openResolve(w)">改状态</button>
                <button class="btn btn-sm btn-danger" :disabled="busyId === w.id" @click="del(w)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">还没有许愿数据</div>
      </div>
    </div>

    <!-- 修改状态弹窗 -->
    <div v-if="resolving" class="mask" @click.self="resolving = null">
      <div class="modal" style="max-width: 440px">
        <div class="modal-head">
          <h3>修改状态</h3>
          <button class="btn btn-sm btn-ghost" @click="resolving = null">关闭</button>
        </div>
        <div class="modal-body">
          <div class="small muted mb-14">
            「{{ resolving.anime_name }}」当前：{{ statusCn(resolving.status) }}
          </div>
          <div class="stack">
            <div class="field">
              <label class="label">新状态</label>
              <select v-model.number="resolveStatus" class="select">
                <option :value="0">许愿中</option>
                <option :value="2">实现中</option>
                <option :value="1">已实现</option>
                <option :value="3">暂无计划</option>
              </select>
            </div>
            <div class="field">
              <label class="label">备注（可选）</label>
              <textarea v-model="resolveNote" class="textarea" rows="2" placeholder="例如：已入库第 6 集，可追"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="resolving = null">取消</button>
          <button class="btn btn-primary" :disabled="busyId === resolving.id" @click="confirmResolve">确认</button>
        </div>
      </div>
    </div>
  </div>
</template>
