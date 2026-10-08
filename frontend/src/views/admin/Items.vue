<script setup>
import { ref, onMounted, computed } from 'vue'
import { api, formatTime, formatSize, epLabel } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 50
const busy = ref('')

const filters = ref({ status: '', unmatched: false, keyword: '' })

const STATUS_CN = { 0: '待处理', 1: '已加种', 2: '已入库', 3: '已忽略', 4: '失败' }
const STATUS_TAG = { 0: 'tag-brand', 1: 'tag-warn', 2: 'tag-ok', 3: 'tag-mute', 4: 'tag-danger' }

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/items', {
      page: page.value,
      per_page: perPage,
      status: filters.value.status === '' ? '' : filters.value.status,
      unmatched: filters.value.unmatched ? 1 : '',
      keyword: filters.value.keyword.trim(),
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

async function download(it) {
  busy.value = 'dl' + it.id
  try {
    const r = await api.post(`/api/admin/items/${it.id}/download`)
    toast(r.msg || '已加种')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function ignore(it) {
  try {
    await api.post(`/api/admin/items/${it.id}/ignore`)
    toast('已忽略')
    await load()
  } catch (e) {
    toastErr(e)
  }
}

/* ---------------- 一键建番 ---------------- */
const createShow = ref(false)
const createForm = ref({})
const creating = ref(false)
const draftLoading = ref(false)

async function openCreate(it) {
  createForm.value = {
    item_id: it.id,
    title: '',
    title_cn: '',
    year: '',
    season: '',
    cover: '',
    pattern: '',
    ep_capture: 1,
    auto_download: true,
    sourceTitle: it.title,
  }
  createShow.value = true
  draftLoading.value = true
  try {
    const d = await api.post('/api/admin/rules/suggest', { title: it.title })
    createForm.value.title = d.title_key || ''
    createForm.value.title_cn = d.title_key || ''
    createForm.value.pattern = d.pattern || ''
    createForm.value.ep_capture = 1
  } catch {
    /* 草稿失败不影响手填 */
  }
  draftLoading.value = false
}

async function submitCreate() {
  const f = createForm.value
  if (!f.title_cn.trim() && !f.title.trim()) {
    toast('请填写番剧名', 'err')
    return
  }
  if (!f.pattern.trim()) {
    toast('请填写匹配正则', 'err')
    return
  }
  creating.value = true
  try {
    const r = await api.post('/api/admin/items/quick-create', {
      item_id: f.item_id,
      title: f.title.trim() || f.title_cn.trim(),
      title_cn: f.title_cn.trim(),
      year: f.year ? Number(f.year) : 0,
      season: f.season ? Number(f.season) : 0,
      cover: f.cover.trim(),
      pattern: f.pattern.trim(),
      ep_capture: Number(f.ep_capture),
      auto_download: !!f.auto_download,
    })
    const bits = [r.backfilled > 0 ? `已建番并归类 ${r.backfilled} 条历史条目` : '已建番']
    if (r.download) {
      const s = []
      if (r.download.added) s.push(`加种 ${r.download.added} 条`)
      if (r.download.blocked) s.push(`跳过 ${r.download.blocked} 条`)
      if (r.download.failed) s.push(`失败 ${r.download.failed} 条`)
      bits.push(s.length ? s.join('，') : '没有待下载的条目')
    }
    toast(bits.join('，'))
    createShow.value = false
    await load()
  } catch (e) {
    toastErr(e)
  }
  creating.value = false
}

/* ---------------- 指派 ---------------- */
const assignShow = ref(false)
const assignTarget = ref(null)
const assignKw = ref('')
const assignOpts = ref([])

async function openAssign(it) {
  assignTarget.value = it
  assignKw.value = ''
  assignOpts.value = []
  assignShow.value = true
  await searchAssign()
}

async function searchAssign() {
  try {
    const d = await api.get('/api/admin/animes', { keyword: assignKw.value.trim(), per_page: 20 })
    assignOpts.value = d.list || []
  } catch {
    /* 忽略 */
  }
}

async function doAssign(a) {
  try {
    await api.post(`/api/admin/items/${assignTarget.value.id}/assign`, { anime_id: a.id })
    toast(`已指派给「${a.title_cn || a.title}」`)
    assignShow.value = false
    await load()
  } catch (e) {
    toastErr(e)
  }
}

onMounted(load)

const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>条目池</h1>
        <div class="small muted-3 mt-8">RSS 抓到的原始条目。命中规则会自动加种，没命中的可以手动建番或指派。</div>
      </div>
      <button class="btn btn-sm" @click="load">刷新</button>
    </div>

    <div class="card card-tight mb-14">
      <div class="wrapflex">
        <select v-model="filters.status" class="select input-sm" style="width: 130px" @change="search">
          <option value="">全部状态</option>
          <option value="0">待处理</option>
          <option value="1">已加种</option>
          <option value="2">已入库</option>
          <option value="3">已忽略</option>
          <option value="4">失败</option>
        </select>
        <label class="check">
          <input type="checkbox" v-model="filters.unmatched" @change="search" />
          只看未归类
        </label>
        <input
          v-model="filters.keyword"
          class="input input-sm"
          style="width: 220px"
          placeholder="搜索标题"
          @keyup.enter="search"
        />
        <button class="btn btn-sm" @click="search">筛选</button>
        <div class="spacer"></div>
        <span class="small muted-3">共 {{ total }} 条</span>
      </div>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th>标题</th>
              <th style="width: 96px">字幕组</th>
              <th style="width: 56px">集</th>
              <th style="width: 66px">画质</th>
              <th style="width: 76px">状态</th>
              <th style="width: 130px">归属</th>
              <th style="width: 96px">发布</th>
              <th style="width: 190px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="it in list" :key="it.id">
              <td>
                <div class="break" style="max-width: 460px">{{ it.title }}</div>
                <div v-if="it.message" class="tiny muted-3">{{ it.message }}</div>
              </td>
              <td class="tiny muted nowrap">{{ it.subgroup || '—' }}</td>
              <td class="nowrap">{{ it.ep_no ? epLabel(it.ep_no) : '—' }}</td>
              <td class="nowrap tiny">{{ it.resolution || '—' }}</td>
              <td>
                <span class="tag" :class="STATUS_TAG[it.status]">{{ STATUS_CN[it.status] }}</span>
              </td>
              <td class="tiny">
                <span v-if="it.anime_id" class="ellip" style="max-width: 120px; display: inline-block">
                  {{ it.anime_title_cn || it.anime_title }}
                </span>
                <span v-else class="muted-3">未归类</span>
              </td>
              <td class="nowrap muted-3 tiny">{{ formatTime(it.pub_date) }}</td>
              <td class="right nowrap">
                <button
                  v-if="it.has_magnet && it.status !== 1 && it.status !== 2"
                  class="btn btn-sm"
                  :disabled="!!busy"
                  @click="download(it)"
                >{{ busy === 'dl' + it.id ? '…' : '加种' }}</button>
                <button v-if="!it.anime_id" class="btn btn-sm btn-ghost" @click="openCreate(it)">建番</button>
                <button v-else-if="!it.anime_title_cn && it.status === 0" class="btn btn-sm btn-ghost" @click="openAssign(it)">改派</button>
                <button v-if="it.status === 0" class="btn btn-sm btn-ghost" @click="ignore(it)">忽略</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">没有符合条件的条目</div>
      </div>
    </div>

    <div v-if="pages > 1" class="pager">
      <button class="btn btn-sm" :disabled="page <= 1" @click="page--; load()">上一页</button>
      <span class="info">{{ page }} / {{ pages }}</span>
      <button class="btn btn-sm" :disabled="page >= pages" @click="page++; load()">下一页</button>
    </div>

    <!-- 一键建番 -->
    <div v-if="createShow" class="mask" @click.self="createShow = false">
      <div class="modal modal-wide">
        <div class="modal-head">
          <h3>从条目建番</h3>
          <button class="btn btn-sm btn-ghost" @click="createShow = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="stack">
            <div class="alert">
              <div class="tiny muted-3">来源标题</div>
              <div class="break">{{ createForm.sourceTitle }}</div>
            </div>

            <div class="form-grid">
              <div class="field">
                <label class="label">中文名<span class="req">*</span></label>
                <input v-model="createForm.title_cn" class="input" />
              </div>
              <div class="field">
                <label class="label">原始名 / 主标题</label>
                <input v-model="createForm.title" class="input" />
              </div>
              <div class="field">
                <label class="label">年份</label>
                <input v-model="createForm.year" class="input" type="number" placeholder="2026" />
              </div>
              <div class="field">
                <label class="label">季度</label>
                <select v-model="createForm.season" class="select">
                  <option value="">—</option>
                  <option value="1">春</option>
                  <option value="2">夏</option>
                  <option value="3">秋</option>
                  <option value="4">冬</option>
                </select>
              </div>
            </div>

            <div class="field">
              <label class="label">封面地址（可选）</label>
              <input v-model="createForm.cover" class="input" placeholder="https://..." />
            </div>

            <div class="divider"></div>

            <div class="field">
              <label class="label">
                匹配正则<span class="req">*</span>
                <span v-if="draftLoading" class="muted-3"> · 正在生成草稿…</span>
              </label>
              <input v-model="createForm.pattern" class="input input-mono" />
              <span class="tiny muted-3">
                保存后会立刻归类条目池里所有未归类的条目，并按下面的开关决定是否立即加种
              </span>
            </div>

            <div class="field" style="max-width: 200px">
              <label class="label">集号所在捕获组</label>
              <select v-model.number="createForm.ep_capture" class="select">
                <option :value="0">自动猜</option>
                <option v-for="n in [1, 2, 3]" :key="n" :value="n">捕获组 {{ n }}</option>
              </select>
            </div>

            <label class="check mt-8">
              <input v-model="createForm.auto_download" type="checkbox" />
              建好后自动下载命中条目
            </label>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="createShow = false">取消</button>
          <button class="btn btn-primary" :disabled="creating" @click="submitCreate">
            {{ creating ? '创建中…' : '创建番剧并归类' }}
          </button>
        </div>
      </div>
    </div>

    <!-- 指派 -->
    <div v-if="assignShow" class="mask" @click.self="assignShow = false">
      <div class="modal">
        <div class="modal-head">
          <h3>指派到已有番剧</h3>
          <button class="btn btn-sm btn-ghost" @click="assignShow = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="row-gap mb-14">
            <input v-model="assignKw" class="input" placeholder="搜索番剧" @keyup.enter="searchAssign" />
            <button class="btn" @click="searchAssign">搜索</button>
          </div>
          <div class="stack-s" style="max-height: 360px; overflow: auto">
            <button
              v-for="a in assignOpts"
              :key="a.id"
              class="btn btn-block"
              style="justify-content: flex-start"
              @click="doAssign(a)"
            >{{ a.title_cn || a.title }}</button>
            <div v-if="!assignOpts.length" class="empty">没有匹配的番剧</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
