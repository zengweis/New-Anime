<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api, formatTime, formatSize, SEASON_CN } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 30
const kw = ref('')

const show = ref(false)
const editing = ref(null)
const busy = ref(false)
const form = ref({})

/* ---------------- 管理弹窗：已下载集数 + 手动补录 ---------------- */
const manageTarget = ref(null)
const episodes = ref([])
const loadingEps = ref(false)
const magnet = ref('')
const magnetTitle = ref('')
const backfilling = ref(false)
const s3Enabled = ref(false)

/* ---------------- 补档：磁力 / 远程 两种方式 ---------------- */
const backfillTab = ref('magnet')
const remote = reactive({
  list: [],
  cursor: null,
  truncated: false,
  loading: false,
  prefix: '',
  busy: false,
})
const selKeys = ref([])
const remoteTitle = ref('')
const remoteEpNo = ref('')
const epBusy = ref(0)

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/animes', { page: page.value, per_page: perPage, keyword: kw.value.trim() })
    list.value = d.list || []
    total.value = d.total || 0
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

function openNew() {
  editing.value = null
  form.value = { title: '', title_cn: '', alias: '', cover: '', year: '', season: '', summary: '', status: 0 }
  show.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = {
    title: a.title || '',
    title_cn: a.title_cn || '',
    alias: a.alias || '',
    cover: a.cover || '',
    year: a.year || '',
    season: a.season || '',
    summary: a.summary || '',
    status: a.status ?? 0,
  }
  show.value = true
}

async function submit() {
  if (!form.value.title.trim() && !form.value.title_cn.trim()) {
    toast('标题不能为空', 'err')
    return
  }
  busy.value = true
  try {
    const payload = {
      ...form.value,
      title: form.value.title.trim() || form.value.title_cn.trim(),
      year: form.value.year ? Number(form.value.year) : 0,
      season: form.value.season ? Number(form.value.season) : 0,
      status: Number(form.value.status),
    }
    if (editing.value) {
      await api.put(`/api/admin/animes/${editing.value.id}`, payload)
      toast('已保存')
    } else {
      await api.post('/api/admin/animes', payload)
      toast('已创建')
    }
    show.value = false
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = false
}

async function del(a) {
  if (!window.confirm(`删除「${a.title_cn || a.title}」？\n该操作会同时删除它的所有集数和规则，不可恢复。`)) return
  try {
    await api.del(`/api/admin/animes/${a.id}`)
    toast('已删除')
    await load()
  } catch (e) {
    toastErr(e)
  }
}

async function openManage(a) {
  manageTarget.value = a
  magnet.value = ''
  magnetTitle.value = ''
  episodes.value = []
  backfillTab.value = 'magnet'
  resetRemote()
  loadingEps.value = true
  try {
    const d = await api.get(`/api/admin/animes/${a.id}/episodes`)
    episodes.value = d.list || []
    s3Enabled.value = !!d.s3_enabled
  } catch (e) {
    toastErr(e)
  }
  loadingEps.value = false
}

function resetRemote() {
  remote.list = []
  remote.cursor = null
  remote.truncated = false
  remote.prefix = ''
  selKeys.value = []
  remoteTitle.value = ''
  remoteEpNo.value = ''
}

async function loadRemote(more = false) {
  if (!manageTarget.value) return
  remote.loading = true
  try {
    const d = await api.get(`/api/admin/animes/${manageTarget.value.id}/remote-candidates`, {
      prefix: remote.prefix.trim(),
      limit: 100,
    })
    const incoming = d.list || []
    remote.list = more ? remote.list.concat(incoming) : incoming
    remote.cursor = d.cursor || null
    remote.truncated = !!d.truncated
  } catch (e) {
    toastErr(e)
  }
  remote.loading = false
}

function toggleKey(k) {
  const i = selKeys.value.indexOf(k)
  if (i >= 0) selKeys.value.splice(i, 1)
  else selKeys.value.push(k)
}

function toggleAllRemote() {
  const free = remote.list.filter((o) => !o.used).map((o) => o.key)
  if (selKeys.value.length === free.length && free.length > 0) selKeys.value = []
  else selKeys.value = free
}

async function submitRemote() {
  if (!selKeys.value.length) {
    toast('请先勾选要补档的远程文件', 'err')
    return
  }
  remote.busy = true
  try {
    const d = await api.post(`/api/admin/animes/${manageTarget.value.id}/backfill-remote`, {
      keys: selKeys.value,
      // 只勾一个时才允许人工指定集数/标题
      title: selKeys.value.length === 1 ? remoteTitle.value.trim() : '',
      ep_no: selKeys.value.length === 1 ? remoteEpNo.value.trim() : '',
    })
    toast(d.msg || '补档完成')
    if (d.failed?.length) {
      d.failed.slice(0, 3).forEach((f) => toast(`${f.key}：${f.error}`, 'err'))
    }
    selKeys.value = []
    remoteEpNo.value = ''
    remoteTitle.value = ''
    await openManage(manageTarget.value)
    await load()
  } catch (e) {
    toastErr(e)
  }
  remote.busy = false
}

/* ---------------- 单集：上传到对象存储 / 解除关联 ---------------- */
async function uploadEp(e, force = false) {
  epBusy.value = e.id
  try {
    const d = await api.post(`/api/admin/s3/upload/${e.id}`, { force })
    toast(d?.reason === 'waiting_remux' ? '等待 remux 完成后自动上传' : '上传完成' + (d?.purged ? '，本地已删除' : ''))
    await openManage(manageTarget.value)
  } catch (err) {
    toastErr(err)
  }
  epBusy.value = 0
}

async function unlinkEp(e) {
  if (!window.confirm('解除该集的远端关联？\n远端对象不会被删除，本地文件保留。')) return
  epBusy.value = e.id
  try {
    await api.post(`/api/admin/s3/unlink/${e.id}`)
    toast('已解除关联')
    await openManage(manageTarget.value)
  } catch (err) {
    toastErr(err)
  }
  epBusy.value = 0
}

const STORAGE_TAG = {
  local: ['本地', 'tag-mute'],
  both: ['本地+远程', 'tag-brand'],
  remote: ['远程', 'tag-ok'],
  missing: ['文件缺失', 'tag-danger'],
}

async function backfill() {
  if (!manageTarget.value) return
  if (!magnet.value.trim() || !magnet.value.trim().startsWith('magnet:')) {
    toast('请粘贴合法的磁力链接', 'err')
    return
  }
  backfilling.value = true
  try {
    const d = await api.post(`/api/admin/animes/${manageTarget.value.id}/backfill`, {
      magnet: magnet.value.trim(),
      title: magnetTitle.value.trim(),
    })
    toast(d.ep_no ? `已补录 第${d.ep_no}集` : '已补录')
    magnet.value = ''
    magnetTitle.value = ''
    await openManage(manageTarget.value)
    await load()
  } catch (e) {
    toastErr(e)
  }
  backfilling.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <h1>番剧管理</h1>
      <button class="btn btn-primary btn-sm" @click="openNew">新建番剧</button>
    </div>

    <div class="card card-tight mb-14">
      <div class="row-gap">
        <input v-model="kw" class="input input-sm" style="width: 240px" placeholder="搜索标题 / 原名" @keyup.enter="page = 1; load()" />
        <button class="btn btn-sm" @click="page = 1; load()">搜索</button>
        <div class="spacer"></div>
        <span class="small muted-3">共 {{ total }} 部</span>
      </div>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 46px">ID</th>
              <th>标题</th>
              <th style="width: 96px">年份</th>
              <th style="width: 66px">集数</th>
              <th style="width: 82px">规则</th>
              <th style="width: 82px">状态</th>
              <th style="width: 106px">更新</th>
              <th style="width: 180px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in list" :key="a.id">
              <td class="muted-3">{{ a.id }}</td>
              <td>
                <div>{{ a.title_cn || a.title }}</div>
                <div v-if="a.title_cn && a.title !== a.title_cn" class="tiny muted-3 ellip" style="max-width: 320px">
                  {{ a.title }}
                </div>
              </td>
              <td class="nowrap">
                {{ a.year || '—' }}
                <span v-if="a.season" class="muted-3">{{ SEASON_CN[a.season] }}</span>
              </td>
              <td>{{ a.real_eps }}</td>
              <td>
                <span class="tag" :class="a.rule_count > 0 ? 'tag-ok' : 'tag-warn'">{{ a.rule_count }}</span>
              </td>
              <td>
                <span class="tag" :class="a.status === 1 ? 'tag-mute' : 'tag-brand'">
                  {{ a.status === 1 ? '完结' : '连载' }}
                </span>
              </td>
              <td class="nowrap muted-3 small">{{ formatTime(a.updated_at) }}</td>
              <td class="right nowrap">
                <button class="btn btn-sm" @click="openManage(a)">管理</button>
                <button class="btn btn-sm btn-ghost" @click="openEdit(a)">编辑</button>
                <button class="btn btn-sm btn-danger" @click="del(a)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">还没有番剧</div>
        <div>去「匹配规则」页粘贴一条 RSS 标题，一键建番</div>
      </div>
    </div>

    <div v-if="total > perPage" class="pager">
      <button class="btn btn-sm" :disabled="page <= 1" @click="page--; load()">上一页</button>
      <span class="info">{{ page }} / {{ Math.ceil(total / perPage) }}</span>
      <button class="btn btn-sm" :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
    </div>

    <!-- 编辑弹窗 -->
    <div v-if="show" class="mask" @click.self="show = false">
      <div class="modal">
        <div class="modal-head">
          <h3>{{ editing ? '编辑番剧' : '新建番剧' }}</h3>
          <button class="btn btn-sm btn-ghost" @click="show = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="stack">
            <div class="form-grid">
              <div class="field">
                <label class="label">中文名</label>
                <input v-model="form.title_cn" class="input" placeholder="用于展示" />
              </div>
              <div class="field">
                <label class="label">原始名 / 主标题<span class="req">*</span></label>
                <input v-model="form.title" class="input" placeholder="留空则用中文名" />
              </div>
              <div class="field">
                <label class="label">年份</label>
                <input v-model="form.year" class="input" type="number" placeholder="2026" />
              </div>
              <div class="field">
                <label class="label">季度</label>
                <select v-model="form.season" class="select">
                  <option value="">—</option>
                  <option value="1">春</option>
                  <option value="2">夏</option>
                  <option value="3">秋</option>
                  <option value="4">冬</option>
                </select>
              </div>
              <div class="field">
                <label class="label">状态</label>
                <select v-model="form.status" class="select">
                  <option :value="0">连载中</option>
                  <option :value="1">已完结</option>
                </select>
              </div>
            </div>

            <div class="field">
              <label class="label">别名（| 分隔）</label>
              <input v-model="form.alias" class="input" placeholder="其他译名" />
            </div>

            <div class="field">
              <label class="label">封面地址</label>
              <input v-model="form.cover" class="input" placeholder="https://..." />
            </div>

            <div class="field">
              <label class="label">简介</label>
              <textarea v-model="form.summary" class="textarea" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="show = false">取消</button>
          <button class="btn btn-primary" :disabled="busy" @click="submit">
            {{ busy ? '保存中…' : '保存' }}
          </button>
        </div>
      </div>
    </div>

    <!-- 管理弹窗：已下载集数 + 手动补录 -->
    <div v-if="manageTarget" class="mask" @click.self="manageTarget = null">
      <div class="modal" style="max-width: 880px">
        <div class="modal-head">
          <h3>管理「{{ manageTarget.title_cn || manageTarget.title }}」</h3>
          <button class="btn btn-sm btn-ghost" @click="manageTarget = null">关闭</button>
        </div>
        <div class="modal-body">
          <!-- 已下载集数 -->
          <div class="between mb-8">
            <span class="small muted-3">已入库 {{ episodes.length }} 集</span>
            <span v-if="s3Enabled" class="tiny muted-3">存储列显示本地 / 远程状态</span>
          </div>
          <div v-if="loadingEps" class="loading"><span class="spin"></span> 加载中</div>
          <div v-else-if="episodes.length" style="max-height: 300px; overflow: auto" class="card card-tight">
            <table class="table">
              <thead>
                <tr>
                  <th style="width: 52px">集</th>
                  <th>标题</th>
                  <th style="width: 100px">编码</th>
                  <th style="width: 86px">大小</th>
                  <th style="width: 92px">存储</th>
                  <th style="width: 76px">状态</th>
                  <th v-if="s3Enabled" style="width: 90px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in episodes" :key="e.id">
                  <td>{{ e.ep_no }}</td>
                  <td class="ellip" style="max-width: 260px" :title="e.title">{{ e.title }}</td>
                  <td class="nowrap muted-3 small">
                    {{ e.vcodec ? e.vcodec.toUpperCase() : '?' }}/{{ (e.acodec || '?').toUpperCase() }}
                  </td>
                  <td class="nowrap">{{ formatSize(e.file_size) }}</td>
                  <td>
                    <span class="tag" :class="(STORAGE_TAG[e.storage] || STORAGE_TAG.local)[1]">
                      {{ (STORAGE_TAG[e.storage] || STORAGE_TAG.local)[0] }}
                    </span>
                  </td>
                  <td>
                    <span class="tag" :class="e.s3_status === 2 ? 'tag-danger' : (e.s3_status === 1 ? 'tag-ok' : 'tag-mute')">
                      {{ e.s3_status === 2 ? '上传失败' : (e.s3_status === 1 ? '已上传' : (e.remuxed ? '已优化' : '原始')) }}
                    </span>
                  </td>
                  <td v-if="s3Enabled" class="right nowrap">
                    <button
                      v-if="e.storage !== 'remote' || e.s3_status !== 1"
                      class="btn btn-sm btn-ghost"
                      :disabled="epBusy === e.id"
                      :title="e.s3_err || '上传到对象存储'"
                      @click="uploadEp(e, e.s3_status === 1)"
                    >{{ epBusy === e.id ? '…' : '上传' }}</button>
                    <button
                      v-if="e.s3_status === 1"
                      class="btn btn-sm btn-ghost"
                      :disabled="epBusy === e.id"
                      title="解除远端关联（不删远端对象）"
                      @click="unlinkEp(e)"
                    >解绑</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="empty">
            <div class="big">还没有入库任何集数</div>
            <div>用下面「补档」粘贴磁力链接，或从对象存储里引用已有文件</div>
          </div>

          <div class="divider"></div>

          <!-- 补档：磁力 / 远程 -->
          <div class="between mb-8">
            <div class="small muted">补档（补全缺失集数）</div>
            <div class="seg">
              <button class="seg-item" :class="{ on: backfillTab === 'magnet' }" @click="backfillTab = 'magnet'">磁力补录</button>
              <button
                class="seg-item"
                :class="{ on: backfillTab === 'remote' }"
                :disabled="!s3Enabled"
                :title="s3Enabled ? '' : '对象存储未启用'"
                @click="backfillTab = 'remote'; if (!remote.list.length) loadRemote()"
              >从远程选择</button>
            </div>
          </div>

          <!-- 磁力 -->
          <div v-if="backfillTab === 'magnet'" class="stack-s">
            <input v-model="magnet" class="input input-mono" placeholder="magnet:?xt=urn:btih:..." />
            <input v-model="magnetTitle" class="input" placeholder="标题（可选，磁力里带 dn= 会自动识别）" />
            <div>
              <button class="btn btn-primary btn-sm" :disabled="backfilling" @click="backfill">
                {{ backfilling ? '补录中…' : '补录' }}
              </button>
            </div>
          </div>

          <!-- 远程 -->
          <div v-else class="stack-s">
            <div class="row-gap">
              <input
                v-model="remote.prefix"
                class="input input-mono grow"
                placeholder="前缀，留空用设置里的默认前缀"
                @keyup.enter="loadRemote()"
              />
              <button class="btn btn-sm" :disabled="remote.loading" @click="loadRemote()">
                {{ remote.loading ? '拉取中…' : '拉取列表' }}
              </button>
            </div>

            <div v-if="remote.loading && !remote.list.length" class="loading"><span class="spin"></span> 拉取远程文件列表</div>
            <template v-else-if="remote.list.length">
              <div class="between">
                <span class="tiny muted-3">已选 {{ selKeys.length }} / {{ remote.list.length }} 个</span>
                <button class="btn btn-sm btn-ghost" @click="toggleAllRemote">全选未引用</button>
              </div>
              <div style="max-height: 260px; overflow: auto" class="card card-tight">
                <table class="table">
                  <thead>
                    <tr>
                      <th style="width: 40px"></th>
                      <th>文件</th>
                      <th style="width: 84px">大小</th>
                      <th style="width: 116px">上传时间</th>
                      <th style="width: 78px"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="o in remote.list" :key="o.key">
                      <td>
                        <input
                          type="checkbox"
                          :checked="selKeys.includes(o.key)"
                          :disabled="!!o.used"
                          @change="toggleKey(o.key)"
                        />
                      </td>
                      <td class="ellip" style="max-width: 320px" :title="o.key">
                        {{ o.name }}
                        <div class="tiny muted-3 ellip">{{ o.folder || '/' }}</div>
                      </td>
                      <td class="nowrap">{{ formatSize(o.size) }}</td>
                      <td class="nowrap muted-3 tiny">{{ formatTime(String(o.modified).slice(0, 19).replace('T', ' ')) }}</td>
                      <td>
                        <span v-if="o.used" class="tag tag-mute">已引用</span>
                        <span v-else-if="selKeys.includes(o.key)" class="tag tag-brand">已选</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="remote.truncated" class="right">
                <button class="btn btn-sm btn-ghost" :disabled="remote.loading" @click="loadRemote(true)">加载更多</button>
              </div>

              <div v-if="selKeys.length === 1" class="form-grid">
                <div class="field">
                  <label class="label">标题</label>
                  <input v-model="remoteTitle" class="input" placeholder="留空用文件名" />
                </div>
                <div class="field">
                  <label class="label">集数</label>
                  <input v-model="remoteEpNo" class="input" placeholder="留空自动识别" />
                </div>
              </div>

              <div class="row-gap">
                <button class="btn btn-primary btn-sm" :disabled="remote.busy" @click="submitRemote">
                  {{ remote.busy ? '补档中…' : `把选中的 ${selKeys.length} 个引用为剧集` }}
                </button>
                <span class="tiny muted-3">引用只建记录，不下载文件；播放时直接走对象存储直链</span>
              </div>
            </template>
            <div v-else class="empty">
              <div class="big">还没有拉取远程列表</div>
              <div>点「拉取列表」读取对象存储里可用的文件</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
