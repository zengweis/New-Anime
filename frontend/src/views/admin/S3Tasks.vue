<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { api, formatSize, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 30
const filter = ref(-1)
const st = ref(null)

/* 自动刷新：任务由常驻进程执行，后台只能靠轮询看到进度 */
const auto = ref(true)
const refreshing = ref(false)
let timer = null
let lastLoad = 0

const POLL_MS = 2000

const TABS = [
  { k: -1, cn: '全部' },
  { k: 0, cn: '进行中' },
  { k: 1, cn: '成功' },
  { k: 2, cn: '失败' },
]

const STATUS = {
  0: ['进行中', 'tag-brand'],
  1: ['成功', 'tag-ok'],
  2: ['失败', 'tag-danger'],
}

const ACTION_CN = {
  upload: '上传',
  import_remote: '远程引用',
  purge: '清理本地',
  delete_remote: '删除远端',
}

function pct(r) {
  if (r.status === 1) return 100
  if (r.bytes_total > 0) return Math.min(100, Math.round((r.bytes_done * 100) / r.bytes_total))
  return 0
}

function elapsed(r) {
  const ms = r.status === 0 ? r.elapsed_ms : r.duration_ms
  if (!ms) return '—'
  const s = ms / 1000
  return s < 60 ? `${s.toFixed(1)}s` : `${Math.floor(s / 60)}m${String(Math.round(s % 60)).padStart(2, '0')}s`
}

/** 该行是否需要提醒（失败 / 疑似中断 / 上传成功但本地没删掉） */
function warnOf(r) {
  if (r.status === 2) return r.error || '失败'
  if (r.stale) return `任务已中断（${Math.round(r.elapsed_ms / 1000)}s 无进展，可能进程被重启）`
  if (r.status === 1 && r.purge_error) return r.purge_error
  return ''
}

async function load(quiet = false) {
  if (refreshing.value) return
  refreshing.value = true
  if (!quiet) loading.value = true
  try {
    const d = await api.get('/api/admin/s3/tasks', {
      status: filter.value,
      page: page.value,
      per_page: perPage,
    })
    list.value = d.list || []
    total.value = d.total || 0
    st.value = d.stats || null
    lastLoad = Date.now()
  } catch (e) {
    if (!quiet) toastErr(e)
    // 轮询失败不打断：网络抖一下不该弹一堆提示
    if (quiet) auto.value = false
  }
  refreshing.value = false
  loading.value = false
}

function switchFilter(k) {
  filter.value = k
  page.value = 1
  load()
}

function tick() {
  if (!auto.value) return
  if (document.visibilityState !== 'visible') return
  if (Date.now() - lastLoad < POLL_MS - 200) return
  load(true)
}

async function retry(r) {
  try {
    const d = await api.post(`/api/admin/s3/tasks/retry/${r.id}`)
    toast(d?.msg || '已重新排队')
    await load(true)
  } catch (e) {
    toastErr(e)
  }
}

async function clearDone() {
  if (!window.confirm('清理 7 天前的成功记录？（进行中与失败的会保留）')) return
  try {
    const d = await api.post('/api/admin/s3/tasks/clear', { days: 7 })
    toast(d?.msg || '已清理')
    await load()
  } catch (e) {
    toastErr(e)
  }
}

async function pushQueue() {
  try {
    const d = await api.post('/api/admin/s3/upload-batch', { limit: 2 })
    toast(d?.msg || '已处理')
    await load(true)
  } catch (e) {
    toastErr(e)
  }
}

const usage = computed(() => st.value?.usage || {})
const locks = computed(() => st.value?.locks || [])
const badLocks = computed(() => locks.value.filter((l) => !l.ok))

/* 环境提醒：这些都是「任务明明该跑却不动」的原因，必须显式说出来 */
const banners = computed(() => {
  const out = []
  const s = st.value
  if (!s) return out
  if (!s.enabled) {
    out.push({ level: 'warn', text: `对象存储未启用或配置不完整：${s.config_hint || '请到设置页填写'}` })
  }
  if (s.enabled && !s.auto_upload) {
    out.push({ level: 'warn', text: '「自动上传」已关闭，下载完的集不会自动上传，只能手动点上传' })
  }
  if (badLocks.value.length) {
    out.push({
      level: 'err',
      text: `有 ${badLocks.value.length} 个任务锁文件当前用户不可用，对应任务会静默不执行：${badLocks.value
        .map((l) => l.name)
        .join('、')}`,
    })
  }
  if (s.daemon?.log_fresh_seconds !== null && s.daemon.log_fresh_seconds > 600) {
    out.push({
      level: 'warn',
      text: `常驻进程已 ${Math.round(s.daemon.log_fresh_seconds / 60)} 分钟没有输出日志，可能已停止运行（systemctl status anime-daemon）`,
    })
  }
  const stale = Number(s.stale || 0)
  if (stale > 0) {
    out.push({ level: 'warn', text: `有 ${stale} 条任务长时间没有进展，疑似进程被重启中断` })
  }
  return out
})

onMounted(() => {
  load()
  timer = setInterval(tick, POLL_MS)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>上传任务</h1>
        <div class="small muted-3 mt-8">
          对象存储的上传队列、实时进度与历史。任务由常驻进程执行，本页每 2 秒自动刷新。
        </div>
      </div>
      <div class="row-gap">
        <label class="switch" title="自动刷新">
          <input type="checkbox" :checked="auto" @change="auto = !auto" />
          <span class="track"></span>
        </label>
        <button class="btn btn-sm btn-ghost" :disabled="refreshing" @click="load()">刷新</button>
        <button class="btn btn-sm" :disabled="!st?.enabled" @click="pushQueue">处理待传队列</button>
      </div>
    </div>

    <!-- 环境提醒 -->
    <div
      v-for="(b, i) in banners"
      :key="i"
      class="alert mb-14"
      :class="b.level === 'err' ? 'alert-warn' : 'alert-brand'"
    >
      {{ b.text }}
    </div>

    <!-- 概况 -->
    <div v-if="st" class="stats mb-14">
      <div class="stat">
        <div class="stat-v">{{ st.running || 0 }}</div>
        <div class="stat-k">进行中</div>
      </div>
      <div class="stat">
        <div class="stat-v">{{ st.ready || 0 }}<span v-if="st.blocked" class="sub">/{{ st.blocked }}</span></div>
        <div class="stat-k">待上传（就绪/等前置）</div>
      </div>
      <div class="stat">
        <div class="stat-v">{{ st.ok_today || 0 }}</div>
        <div class="stat-k">今日成功</div>
      </div>
      <div class="stat">
        <div class="stat-v" :style="st.fail_today ? 'color: var(--danger)' : ''">{{ st.fail_today || 0 }}</div>
        <div class="stat-k">今日失败</div>
      </div>
      <div class="stat">
        <div class="stat-v">{{ formatSize(st.bytes_today || 0) }}</div>
        <div class="stat-k">今日上传量</div>
      </div>
      <div class="stat">
        <div class="stat-v">{{ formatSize(usage.remote_bytes || 0) }}</div>
        <div class="stat-k">远端占用</div>
      </div>
      <div class="stat">
        <div class="stat-v">{{ usage.disk_free !== null && usage.disk_free !== undefined ? formatSize(usage.disk_free) : '—' }}</div>
        <div class="stat-k">本地剩余空间</div>
      </div>
    </div>

    <!-- 存储位置分布 -->
    <div v-if="usage.total !== undefined" class="card card-tight mb-14">
      <div class="row-gap" style="flex-wrap: wrap">
        <span class="small muted-3">剧集存储分布：</span>
        <span class="tag tag-ok">已上传 {{ usage.uploaded || 0 }}</span>
        <span class="tag tag-mute">其中已省本地 {{ usage.purged || 0 }}</span>
        <span class="tag tag-brand">仅远程（补档） {{ usage.remote_only || 0 }}</span>
        <span class="tag tag-mute">保留本地 {{ usage.local_kept || 0 }}</span>
        <span v-if="usage.failed" class="tag tag-danger">上传失败 {{ usage.failed || 0 }}</span>
        <span class="tag tag-warn">待处理 {{ usage.queued || 0 }}</span>
        <span class="spacer"></span>
        <span class="tiny muted-3">
          上传后删本地：{{ st?.delete_local ? '开' : '关' }} · 自动上传：{{ st?.auto_upload ? '开' : '关' }}
        </span>
      </div>
    </div>

    <!-- 任务列表 -->
    <div class="between mb-14">
      <div class="seg">
        <button
          v-for="t in TABS"
          :key="t.k"
          class="seg-item"
          :class="{ on: filter === t.k }"
          @click="switchFilter(t.k)"
        >{{ t.cn }}</button>
      </div>
      <button class="btn btn-sm btn-ghost" @click="clearDone">清理旧记录</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 74px">状态</th>
              <th style="width: 58px">任务</th>
              <th>番剧 / 集</th>
              <th>来源文件</th>
              <th style="width: 168px">进度</th>
              <th style="width: 84px">耗时</th>
              <th style="width: 96px">触发</th>
              <th style="width: 104px">时间</th>
              <th style="width: 76px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in list" :key="r.id">
              <td>
                <span class="tag" :class="(STATUS[r.status] || STATUS[0])[1]">
                  {{ r.stale ? '已中断' : (STATUS[r.status] || STATUS[0])[0] }}
                </span>
              </td>
              <td class="muted-3 tiny">{{ ACTION_CN[r.action] || r.action }}</td>
              <td>
                <div class="ellip" style="max-width: 220px" :title="r.anime_title || ''">
                  {{ r.anime_title || '（番剧已删除）' }}
                </div>
                <div class="tiny muted-3">
                  第 {{ r.ep_no ?? '?' }} 集
                  <span v-if="r.episode_id"> · #{{ r.episode_id }}</span>
                </div>
              </td>
              <td class="ellip tiny" style="max-width: 220px" :title="r.source || r.s3_key || ''">
                {{ r.source || r.s3_key || '—' }}
              </td>
              <td>
                <div class="bar" :class="{ done: r.status === 1, bad: r.status === 2 }">
                  <i :style="{ width: pct(r) + '%' }"></i>
                </div>
                <div class="tiny muted-3 nowrap">
                  <template v-if="r.status === 0">
                    {{ pct(r) }}%
                    <template v-if="r.multipart && r.parts_total">
                      · 分片 {{ r.parts_done }}/{{ r.parts_total }}
                    </template>
                    · {{ formatSize(r.bytes_done) }} / {{ formatSize(r.bytes_total) }}
                  </template>
                  <template v-else>
                    {{ formatSize(r.bytes_total || r.bytes_done) }}
                    <span v-if="r.multipart"> · 分片</span>
                    <span v-if="r.purged"> · 已删本地</span>
                  </template>
                </div>
              </td>
              <td class="nowrap muted-3 small">{{ elapsed(r) }}</td>
              <td class="nowrap tiny muted-3">
                {{ r.trigger === 'manual' ? `手动${r.actor ? '·' + r.actor : ''}` : '自动' }}
              </td>
              <td class="nowrap muted-3 small">{{ formatTime(r.started_at) }}</td>
              <td class="right nowrap">
                <button
                  v-if="r.status === 2 || r.stale"
                  class="btn btn-sm btn-ghost"
                  @click="retry(r)"
                >重试</button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- 失败/告警明细：单独列出，避免把表格撑得很宽 -->
        <template v-for="r in list" :key="'e' + r.id">
          <div v-if="warnOf(r)" class="warn-line">
            <span class="tag" :class="r.status === 2 ? 'tag-danger' : 'tag-warn'">
              #{{ r.id }}
            </span>
            <span class="break">{{ warnOf(r) }}</span>
          </div>
        </template>
      </div>
      <div v-else class="empty">
        <div class="big">还没有上传任务</div>
        <div>剧集下载完成并 remux 好之后会自动出现在这里；也可以在番剧管理页手动上传</div>
      </div>
    </div>

    <div v-if="total > perPage" class="pager">
      <button class="btn btn-sm" :disabled="page <= 1" @click="page--; load()">上一页</button>
      <span class="info">{{ page }} / {{ Math.ceil(total / perPage) }}</span>
      <button class="btn btn-sm" :disabled="page * perPage >= total" @click="page++; load()">下一页</button>
    </div>

    <div v-if="st" class="tiny muted-3 mt-14">
      提示：「待上传」里未就绪的部分在等前置条件（探测 / remux / 字幕抽取）。
      前置没做完就先删本地文件会丢编码信息与内嵌字幕，所以上传队列会等它们收尾。
    </div>
  </div>
</template>

<style scoped>
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
}
.stat {
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 10px 12px;
  background: var(--surface);
}
.stat-v {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.3;
  font-variant-numeric: tabular-nums;
}
.stat-v .sub {
  font-size: 12px;
  font-weight: 400;
  color: var(--text-3);
  margin-left: 2px;
}
.stat-k {
  font-size: 12px;
  color: var(--text-3);
  margin-top: 2px;
}

.bar {
  height: 6px;
  border-radius: 3px;
  background: var(--line);
  overflow: hidden;
  margin-bottom: 4px;
}
.bar > i {
  display: block;
  height: 100%;
  background: var(--brand);
  transition: width 0.3s ease;
}
.bar.done > i {
  background: var(--ok);
}
.bar.bad > i {
  background: var(--danger);
}

.warn-line {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 8px 12px;
  border-top: 1px solid var(--line);
  font-size: 12px;
  color: var(--text-2);
}
</style>
