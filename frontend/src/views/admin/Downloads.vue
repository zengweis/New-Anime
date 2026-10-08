<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { api, formatSize, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const info = ref(null)
const busy = ref('')
const hashes = ref([])
let timer = null

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const d = await api.get('/api/admin/torrents')
    list.value = d.list || []
    info.value = d
  } catch (e) {
    if (!silent) toastErr(e)
  }
  loading.value = false
}

function toggle(hash) {
  const i = hashes.value.indexOf(hash)
  if (i >= 0) hashes.value.splice(i, 1)
  else hashes.value.push(hash)
}

function toggleAll() {
  if (hashes.value.length === list.value.length) hashes.value = []
  else hashes.value = list.value.map((t) => t.hash)
}

async function act(action, targetHashes, deleteFiles = false) {
  const hs = targetHashes || hashes.value
  if (!hs.length) {
    toast('请先选择任务', 'err')
    return
  }
  if (action === 'delete' && !window.confirm(`删除 ${hs.length} 个任务？${deleteFiles ? '\n文件也会一并删除，不可恢复。' : ''}`)) return
  busy.value = action
  try {
    await api.post('/api/admin/torrents/action', { action, hashes: hs, delete_files: deleteFiles })
    toast('操作完成')
    hashes.value = []
    await load(true)
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function saveNow() {
  busy.value = 'sync'
  try {
    const r = await api.post('/api/admin/sync')
    toast(r?.msg || '同步完成')
    await load(true)
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

/** 把所有已完成任务的做种暂停掉（省上行流量） */
async function pauseFinished() {
  busy.value = 'pause'
  try {
    const r = await api.post('/api/admin/torrents/pause-finished')
    toast(r?.msg || '已暂停')
    await load(true)
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

/** 当前有多少个已完成但仍在上传的任务（用于给按钮加提示） */
const seeding = computed(() =>
  list.value.filter((t) => t.done && t.upspeed > 0 && !['pausedUP', 'stoppedUP'].includes(t.state)).length
)

onMounted(async () => {
  await load()
  timer = setInterval(() => load(true), 5000)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>下载任务</h1>
        <div class="small muted-3 mt-8">
          qBittorrent 中的任务。下载完成后点「同步入库」把文件登记进番剧库。
        </div>
      </div>
      <div class="row-gap">
        <button class="btn btn-sm" :disabled="!!busy" @click="load()">刷新</button>
        <button class="btn btn-sm btn-primary" :disabled="!!busy" @click="saveNow">
          {{ busy === 'sync' ? '同步中…' : '同步入库' }}
        </button>
      </div>
    </div>

    <div v-if="info" class="stat-grid mb-14">
      <div class="stat">
        <div class="k">任务数</div>
        <div class="v">{{ list.length }}</div>
      </div>
      <div class="stat">
        <div class="k">下载速率</div>
        <div class="v">{{ formatSize(info.transfer?.dl_info_speed || 0) }}<small>/s</small></div>
      </div>
      <div class="stat">
        <div class="k">上传速率</div>
        <div class="v">{{ formatSize(info.transfer?.up_info_speed || 0) }}<small>/s</small></div>
      </div>
      <div class="stat">
        <div class="k">引擎</div>
        <div class="v" style="font-size: 15px">v{{ info.version }}</div>
      </div>
    </div>

    <div class="row-gap mb-14" style="flex-wrap: wrap">
      <button class="btn btn-sm" :disabled="!hashes.length || !!busy" @click="act('pause')">暂停所选</button>
      <button class="btn btn-sm" :disabled="!hashes.length || !!busy" @click="act('resume')">继续所选</button>
      <button
        class="btn btn-sm"
        :disabled="!!busy"
        :title="seeding ? `把 ${seeding} 个正在做种的已完成任务暂停掉，省上行流量` : '当前没有正在做种的已完成任务'"
        @click="pauseFinished"
      >
        {{ busy === 'pause' ? '暂停中…' : `暂停所有已完成做种${seeding ? `（${seeding}）` : ''}` }}
      </button>
      <button class="btn btn-sm btn-danger" :disabled="!hashes.length || !!busy" @click="act('delete', null, false)">
        删除任务（留文件）
      </button>
      <button class="btn btn-sm btn-danger" :disabled="!hashes.length || !!busy" @click="act('delete', null, true)">
        删除任务并删文件
      </button>
      <span v-if="hashes.length" class="small muted-3">已选 {{ hashes.length }} 个</span>
    </div>

    <div v-if="seeding" class="alert alert-brand mb-14">
      有 {{ seeding }} 个已完成任务正在做种，持续占用服务器上行带宽。
      在「设置 → qBittorrent」开启「下载完成后暂停做种」就会自动停掉，也可以点上面的按钮立即暂停。
      <b>注意</b>：开关开着时，手动「继续所选」的任务会在 1 分钟内被再次暂停；
      想让它持续做种，请先关掉那个开关。
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 34px">
                <input type="checkbox" :checked="hashes.length === list.length && list.length > 0" @change="toggleAll" />
              </th>
              <th>名称</th>
              <th style="width: 132px">进度</th>
              <th style="width: 86px">状态</th>
              <th style="width: 82px">大小</th>
              <th style="width: 96px">速度</th>
              <th style="width: 96px">添加时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in list" :key="t.hash">
              <td>
                <input type="checkbox" :checked="hashes.includes(t.hash)" @change="toggle(t.hash)" />
              </td>
              <td>
                <div class="break" style="max-width: 420px">{{ t.name }}</div>
                <div class="tiny muted-3 break">{{ t.save_path }}</div>
              </td>
              <td>
                <div class="row-gap">
                  <div style="flex: 1; height: 6px; background: var(--surface-2); border-radius: 3px; overflow: hidden; border: 1px solid var(--line)">
                    <div
                      :style="{
                        height: '100%',
                        width: t.progress + '%',
                        background: t.done ? 'var(--ok)' : 'var(--brand)',
                      }"
                    ></div>
                  </div>
                  <span class="tiny muted-3" style="width: 42px; text-align: right">{{ t.progress }}%</span>
                </div>
              </td>
              <td>
                <span class="tag" :class="t.done ? 'tag-ok' : 'tag-brand'">{{ t.state_cn }}</span>
              </td>
              <td class="nowrap small">{{ formatSize(t.size) }}</td>
              <td class="nowrap tiny muted-3">
                <div>↓ {{ formatSize(t.dlspeed) }}/s</div>
                <div>↑ {{ formatSize(t.upspeed) }}/s</div>
              </td>
              <td class="nowrap tiny muted-3">{{ formatTime(t.added_on ? new Date(t.added_on * 1000).toISOString().slice(0, 19).replace('T', ' ') : '') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">没有下载任务</div>
        <div>在「条目池」里加种后，任务会出现在这里</div>
      </div>
    </div>
  </div>
</template>
