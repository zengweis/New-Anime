<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

/**
 * 后台：弹幕管理。
 *
 * 定位很轻 —— 业务上不做审核（写入即可见），这里只提供三件事：
 *   ① 按集/状态/关键词查
 *   ② 勾选批量删除（软删，可恢复，误点不会丢数据）
 *   ③ 导入已有的弹幕文件（B 站 XML 或 JSON）
 *
 * 单集弹幕量通常在几百到几千条，所以分页 50 条 + 关键词搜索就够用，
 * 不做时间轴可视化预览（那是另一个量级的工程）。
 */
const episodes = ref([])
const list = ref([])
const total = ref(0)
const page = ref(1)
const per = ref(50)
const loading = ref(false)
const busy = ref(false)

const filterEpisode = ref(0) // 0 = 全部
const filterStatus = ref('0')
const keyword = ref('')

const selected = ref([]) // 当前页勾选的 id

const pages = computed(() => Math.max(1, Math.ceil(total.value / per.value)))

// 导入
const importEpisode = ref(0)
const importFile = ref(null)
const importBusy = ref(false)
const importResult = ref(null)
const fileEl = ref(null)

function dmClock(ms) {
  const s = Math.max(0, Math.floor(Number(ms) / 1000))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

async function loadEpisodes() {
  try {
    const d = await api.get('/api/admin/danmaku/episodes')
    episodes.value = d?.list || []
  } catch (e) {
    toastErr(e)
  }
}

async function load() {
  loading.value = true
  selected.value = []
  try {
    const d = await api.get('/api/admin/danmaku', {
      episode_id: filterEpisode.value || undefined,
      status: filterStatus.value,
      kw: keyword.value.trim() || undefined,
      page: page.value,
      per: per.value,
    })
    list.value = d?.list || []
    total.value = Number(d?.total) || 0
  } catch (e) {
    toastErr(e)
    list.value = []
    total.value = 0
  }
  loading.value = false
}

function search() {
  page.value = 1
  load()
}

function goto(p) {
  page.value = Math.max(1, Math.min(pages.value, p))
  load()
}

const allChecked = computed(
  () => list.value.length > 0 && selected.value.length === list.value.length
)

function toggleAll(e) {
  selected.value = e.target.checked ? list.value.map((d) => d.id) : []
}

async function removeSelected() {
  if (!selected.value.length) return
  if (!window.confirm(`删除选中的 ${selected.value.length} 条弹幕？（软删除，可在「已删除」里恢复）`)) return
  busy.value = true
  try {
    const r = await api.post('/api/admin/danmaku/delete', { ids: selected.value })
    toast(`已删除 ${r?.deleted ?? 0} 条`)
    await Promise.all([load(), loadEpisodes()])
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}

async function restoreSelected() {
  if (!selected.value.length) return
  busy.value = true
  try {
    const r = await api.post('/api/admin/danmaku/restore', { ids: selected.value })
    toast(`已恢复 ${r?.restored ?? 0} 条`)
    await Promise.all([load(), loadEpisodes()])
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}

async function removeOne(d) {
  busy.value = true
  try {
    await api.post('/api/admin/danmaku/delete', { ids: [d.id] })
    toast('已删除')
    await Promise.all([load(), loadEpisodes()])
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}

async function restoreOne(d) {
  busy.value = true
  try {
    await api.post('/api/admin/danmaku/restore', { ids: [d.id] })
    toast('已恢复')
    await Promise.all([load(), loadEpisodes()])
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}

// ------------------------------------------------------------------
// 导入
// ------------------------------------------------------------------

function pickFile() {
  fileEl.value?.click()
}

async function onFile(e) {
  const f = e.target.files?.[0]
  if (!f) return
  importResult.value = null
  if (f.size > 1024 * 1024) {
    toastErr(
      `文件 ${(f.size / 1024 / 1024).toFixed(1)}MB 超过 1MB 上限（nginx 默认 body 限制）。` +
        '请用命令行导入：php bin/danmaku_import.php ' +
        `${importEpisode.value || filterEpisode.value || '<集id>'} <文件>`
    )
    e.target.value = ''
    return
  }
  // 直接读成文本传给后端 —— 后端两种格式都认（B 站 XML / JSON）
  importFile.value = { name: f.name, size: f.size, text: await f.text() }
  e.target.value = ''
}

async function doImport() {
  const epId = importEpisode.value || filterEpisode.value
  if (!epId) {
    toastErr('请先选择导入到哪一集')
    return
  }
  if (!importFile.value) {
    toastErr('请先选择文件')
    return
  }
  importBusy.value = true
  importResult.value = null
  try {
    const r = await api.post('/api/admin/danmaku/import', {
      episode_id: epId,
      text: importFile.value.text,
    })
    importResult.value = { ok: true, ...r }
    toast(`导入完成：写入 ${r?.inserted ?? 0} 条`)
    importFile.value = null
    filterEpisode.value = Number(epId)
    await Promise.all([load(), loadEpisodes()])
  } catch (e) {
    importResult.value = { ok: false, msg: e?.message || '导入失败' }
    toastErr(e)
  }
  importBusy.value = false
}

function epLabelOf(e) {
  const name = e.title_cn || e.anime_title || ''
  return `#${e.id} ${name} 第 ${e.ep_no} 集（${e.alive} 条）`
}

onMounted(async () => {
  await loadEpisodes()
  await load()
})
</script>

<template>
  <div>
    <div class="between mb-14" style="flex-wrap: wrap; gap: 12px">
      <div>
        <h1>弹幕</h1>
        <div class="small muted-3 mt-8">
          站内用户发的弹幕写入即可见（不做审核）。这里可以按集查看、批量删除，
          或把已有的弹幕文件导入到某一集。删除是软删，可在「已删除」里恢复。
        </div>
      </div>
      <div class="row-gap">
        <button class="btn btn-sm" @click="() => { loadEpisodes(); load() }">刷新</button>
      </div>
    </div>

    <!-- 筛选 -->
    <div class="card card-tight mb-14">
      <div class="row-gap" style="flex-wrap: wrap; gap: 10px">
        <select v-model.number="filterEpisode" class="select input-sm" style="max-width: 320px" @change="search">
          <option :value="0">全部剧集</option>
          <option v-for="e in episodes" :key="e.id" :value="e.id">{{ epLabelOf(e) }}</option>
        </select>
        <select v-model="filterStatus" class="select input-sm" style="width: 130px" @change="search">
          <option value="0">正常</option>
          <option value="9">已删除</option>
          <option value="all">全部状态</option>
        </select>
        <input
          v-model="keyword"
          class="input input-sm"
          style="max-width: 240px"
          type="text"
          placeholder="搜索弹幕内容"
          @keydown.enter="search"
        />
        <button class="btn btn-sm" @click="search">查询</button>
        <span class="grow"></span>
        <span class="small muted-3 nowrap">共 {{ total }} 条</span>
      </div>
    </div>

    <!-- 批量操作 -->
    <div class="row-gap mb-14" style="flex-wrap: wrap; gap: 10px">
      <button class="btn btn-sm btn-danger" :disabled="busy || !selected.length" @click="removeSelected">
        删除所选（{{ selected.length }}）
      </button>
      <button class="btn btn-sm" :disabled="busy || !selected.length" @click="restoreSelected">
        恢复所选
      </button>
      <span class="grow"></span>
      <button class="btn btn-sm" :disabled="page <= 1" @click="goto(page - 1)">上一页</button>
      <span class="small muted-3 nowrap">{{ page }} / {{ pages }}</span>
      <button class="btn btn-sm" :disabled="page >= pages" @click="goto(page + 1)">下一页</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight mb-14">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 40px">
                <input type="checkbox" :checked="allChecked" @change="toggleAll" />
              </th>
              <th style="width: 70px">时间</th>
              <th>内容</th>
              <th style="width: 130px">发送者</th>
              <th style="width: 80px">来源</th>
              <th style="width: 70px">状态</th>
              <th style="width: 150px">发送于</th>
              <th style="width: 90px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in list" :key="d.id">
              <td>
                <input v-model="selected" type="checkbox" :value="d.id" />
              </td>
              <td class="muted-3 nowrap">{{ dmClock(d.t_ms) }}</td>
              <td class="break" style="min-width: 220px">{{ d.text }}</td>
              <td class="small nowrap">
                {{ d.nickname || d.username || '（用户已删除）' }}
                <span class="muted-3">#{{ d.user_id }}</span>
              </td>
              <td class="small muted-3">
                {{ d.source === 'import' ? '导入' : '站内' }}
              </td>
              <td>
                <span class="tag" :class="d.status === 9 ? 'tag-err' : 'tag-ok'">
                  {{ d.status === 9 ? '已删除' : '正常' }}
                </span>
              </td>
              <td class="nowrap muted-3 small">{{ formatTime(d.created_at) }}</td>
              <td class="right nowrap">
                <button
                  v-if="d.status !== 9"
                  class="btn btn-sm btn-danger"
                  :disabled="busy"
                  @click="removeOne(d)"
                >删除</button>
                <button
                  v-else
                  class="btn btn-sm"
                  :disabled="busy"
                  @click="restoreOne(d)"
                >恢复</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">没有符合条件的弹幕</div>
        <div class="small muted-3 mt-8">可以在下面导入一份弹幕文件试试，或换个筛选条件。</div>
      </div>
    </div>

    <!-- 导入 -->
    <div class="card card-tight">
      <h3>导入弹幕</h3>
      <div class="small muted-3 mt-8">
        支持 B 站 XML（<code>&lt;d p="秒,模式,字号,颜色,…"&gt;文本&lt;/d&gt;</code>）与 JSON
        （<code>[[秒, 模式, 颜色, 文本], …]</code>）。导入的弹幕记在你（管理员）名下，
        来源标为「导入」。单文件上限 1MB；更大的文件用
        <code>php bin/danmaku_import.php &lt;集id&gt; &lt;文件&gt;</code>。
      </div>

      <div class="row-gap mt-14" style="flex-wrap: wrap; gap: 10px">
        <select v-model.number="importEpisode" class="select input-sm" style="max-width: 320px">
          <option :value="0">选择目标剧集…</option>
          <option v-for="e in episodes" :key="e.id" :value="e.id">{{ epLabelOf(e) }}</option>
        </select>
        <button class="btn btn-sm" @click="pickFile">选择文件</button>
        <span class="small muted-3">
          {{ importFile ? `${importFile.name}（${(importFile.size / 1024).toFixed(0)}KB）` : '未选择文件' }}
        </span>
        <input
          ref="fileEl"
          type="file"
          accept=".xml,.json,.txt"
          style="display: none"
          @change="onFile"
        />
        <button class="btn btn-sm btn-primary" :disabled="importBusy || !importFile" @click="doImport">
          {{ importBusy ? '导入中…' : '开始导入' }}
        </button>
      </div>

      <div v-if="importResult" class="alert mt-14" :class="importResult.ok ? 'alert-ok' : 'alert-err'">
        <template v-if="importResult.ok">
          解析 {{ importResult.parsed }} 条 · 写入 {{ importResult.inserted }} 条 ·
          跳过 {{ importResult.skipped }} 条
          <div v-for="(e, i) in importResult.errors || []" :key="i" class="tiny muted-3 mt-8">{{ e }}</div>
        </template>
        <template v-else>{{ importResult.msg }}</template>
      </div>
    </div>
  </div>
</template>
