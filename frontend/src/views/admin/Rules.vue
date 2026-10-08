<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '../../api'
import { toast, toastErr } from '../../store/ui'

/* ---------------- 番剧选择 ---------------- */
const kw = ref('')
const options = ref([])
const picked = ref(null)
const showList = ref(false)
const loadingAnime = ref(false)

async function searchAnime() {
  loadingAnime.value = true
  try {
    const d = await api.get('/api/admin/animes', { keyword: kw.value.trim(), per_page: 20 })
    options.value = d.list || []
  } catch (e) {
    toastErr(e)
  }
  loadingAnime.value = false
}

function pick(a) {
  picked.value = a
  showList.value = false
  kw.value = a.title_cn || a.title
  editingId.value = 0
  loadRules()
}

/* ---------------- 规则表单 ---------------- */
const pattern = ref('')
const flags = ref('iu')
const epCapture = ref(1)
const priority = ref(10)
const autoDownload = ref(true)
const result = ref(null)
const testing = ref(false)
const saving = ref(false)

const rules = ref([])
const busyRuleId = ref(0)
const editingId = ref(0) // >0 表示正在改这条规则，保存走 PUT，避免又建一条重复规则

const hitCount = computed(() => result.value?.hit ?? 0)
const totalCount = computed(() => result.value?.total ?? 0)
const previewLimit = ref(500)
/** 只显示命中的条目（未命中不展示，界面更干净） */
const hitItems = computed(() => (result.value?.items || []).filter((it) => it.matched))

/** 把后端的加种统计拼成人话 */
function dlSummary(d) {
  if (!d) return ''
  const bits = []
  if (d.added) bits.push(`加种 ${d.added} 条`)
  if (d.blocked) bits.push(`跳过 ${d.blocked} 条`)
  if (d.failed) bits.push(`失败 ${d.failed} 条`)
  return bits.join('，')
}

async function test() {
  if (!pattern.value.trim()) {
    toast('请先填写正则', 'err')
    return
  }
  testing.value = true
  try {
    result.value = await api.post('/api/admin/rules/preview', {
      pattern: pattern.value,
      flags: flags.value,
      ep_capture: epCapture.value,
      limit: Math.max(1, Math.min(2000, Number(previewLimit.value) || 500)),
    })
  } catch (e) {
    result.value = null
    toastErr(e)
  }
  testing.value = false
}

async function save() {
  if (!picked.value) {
    toast('请先选择所属番剧', 'err')
    return
  }
  if (!pattern.value.trim()) {
    toast('请填写正则', 'err')
    return
  }
  saving.value = true
  try {
    const body = {
      pattern: pattern.value,
      flags: flags.value,
      ep_capture: epCapture.value,
      priority: priority.value,
      enabled: true,
      auto_download: autoDownload.value,
    }
    // 载入已有规则后再保存 → 更新而非新建（否则会多出一条重复规则，重复触发下载）
    const r = editingId.value
      ? await api.put(`/api/admin/rules/${editingId.value}`, body)
      : await api.post('/api/admin/rules', { ...body, anime_id: picked.value.id })

    const bits = [editingId.value ? '规则已更新' : '规则已保存']
    if (r?.backfilled > 0) bits.push(`归类 ${r.backfilled} 条历史条目`)
    if (editingId.value || r?.auto_download) {
      const s = dlSummary(r?.download)
      bits.push(s || '没有待下载的条目')
    } else {
      bits.push('未开启自动下载')
    }
    toast(bits.join('，'))
    editingId.value = 0
    await loadRules()
  } catch (e) {
    toastErr(e)
  }
  saving.value = false
}

/** 手动把该规则命中的待处理条目加进下载队列 */
async function downloadRule(r) {
  busyRuleId.value = r.id
  try {
    const d = await api.post(`/api/admin/rules/${r.id}/download`)
    const s = dlSummary(d)
    toast(s || '没有待下载的条目')
    await loadRules()
  } catch (e) {
    toastErr(e)
  }
  busyRuleId.value = 0
}

async function loadRules() {
  if (!picked.value) return
  try {
    const d = await api.get('/api/admin/rules', { anime_id: picked.value.id })
    rules.value = d.list || []
  } catch {
    /* 忽略 */
  }
}

async function delRule(r) {
  if (!window.confirm(`删除规则「${r.pattern}」？`)) return
  try {
    await api.del(`/api/admin/rules/${r.id}`)
    toast('已删除')
    await loadRules()
  } catch (e) {
    toastErr(e)
  }
}

function useRule(r) {
  pattern.value = r.pattern
  flags.value = r.flags || 'iu'
  epCapture.value = Number(r.ep_capture) || 0
  priority.value = Number(r.priority) || 10
  autoDownload.value = Number(r.auto_download ?? 1) === 1
  editingId.value = Number(r.id) || 0
  test()
}

/** 退出编辑态，回到「新建规则」 */
function newRule() {
  editingId.value = 0
  pattern.value = ''
  result.value = null
}

/* ---------------- 从标题生成草稿 ---------------- */
const suggestFrom = ref('')
const suggesting = ref(false)

async function suggest() {
  if (!suggestFrom.value.trim()) {
    toast('请粘贴一条 RSS 标题', 'err')
    return
  }
  suggesting.value = true
  try {
    const d = await api.post('/api/admin/rules/suggest', { title: suggestFrom.value.trim() })
    pattern.value = d.pattern
    if (d.preview) result.value = d.preview
    toast('已生成正则草稿，可继续微调')
  } catch (e) {
    toastErr(e)
  }
  suggesting.value = false
}

/* ---------------- 高亮 ---------------- */
function highlight(title) {
  return title
}

onMounted(searchAnime)

watch(kw, (v, old) => {
  if (picked.value && v !== (picked.value.title_cn || picked.value.title)) {
    picked.value = null
    rules.value = []
    editingId.value = 0
  }
  if (v !== old) showList.value = true
})
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>匹配规则</h1>
        <div class="small muted-3 mt-8">
          为每部番写正则。保存时立刻归类历史条目，并按开关把命中条目加种到 qBittorrent；
          已有规则可用「下载命中」手动补下。
        </div>
      </div>
    </div>

    <!-- 番剧选择 -->
    <div class="card mb-14">
      <div class="field" style="position: relative; max-width: 460px">
        <label class="label">选择番剧<span class="req">*</span></label>
        <input
          v-model="kw"
          class="input"
          placeholder="搜索番剧名"
          @focus="showList = true"
        />
        <div
          v-if="showList && options.length"
          style="position: absolute; top: 100%; left: 0; right: 0; z-index: 30; margin-top: 4px; max-height: 300px; overflow: auto"
          class="card card-tight"
        >
          <div
            v-for="a in options"
            :key="a.id"
            class="clickable"
            style="padding: 6px 8px; border-radius: 6px"
            @click="pick(a)"
          >
            <div class="small">{{ a.title_cn || a.title }}</div>
            <div class="tiny muted-3">
              {{ a.year || '—' }} · {{ a.real_eps || 0 }} 集 · {{ a.rule_count || 0 }} 条规则
            </div>
          </div>
        </div>
        <div v-if="picked" class="tiny muted-3 mt-8">
          已选：{{ picked.title_cn || picked.title }}（#{{ picked.id }}）
        </div>
      </div>

      <!-- 已有规则 -->
      <template v-if="rules.length">
        <div class="divider"></div>
        <div class="small muted mb-8">已有规则</div>
        <div class="stack-s">
          <div v-for="r in rules" :key="r.id" class="row-gap" style="justify-content: space-between">
            <span class="mono break grow">{{ r.pattern }}</span>
            <div class="row-gap" style="flex: none">
              <span class="tag tag-mute">捕获组 {{ r.ep_capture }}</span>
              <span class="tag tag-mute">优先级 {{ r.priority }}</span>
              <span class="tag" :class="r.hits > 0 ? 'tag-ok' : 'tag-mute'">命中 {{ r.hits }}</span>
              <span class="tag" :class="Number(r.auto_download ?? 1) === 1 ? 'tag-ok' : 'tag-mute'">
                {{ Number(r.auto_download ?? 1) === 1 ? '自动下载' : '仅归类' }}
              </span>
              <button
                class="btn btn-sm"
                :disabled="busyRuleId === r.id"
                @click="downloadRule(r)"
              >
                {{ busyRuleId === r.id ? '加种中…' : '下载命中' }}
              </button>
              <button class="btn btn-sm btn-ghost" @click="useRule(r)">
                {{ editingId === r.id ? '编辑中' : '载入' }}
              </button>
              <button class="btn btn-sm btn-danger" @click="delRule(r)">删除</button>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- 正则编辑 -->
    <div class="card mb-14">
      <div class="card-head">
        <div>
          <h3>{{ editingId ? '编辑规则' : '新建规则' }}</h3>
          <div class="sub">
            {{ editingId ? `正在修改规则 #${editingId}，保存后立即生效` : '支持 /.../iu 写法，也可直接写裸正则' }}
          </div>
        </div>
        <button v-if="editingId" class="btn btn-sm btn-ghost" @click="newRule">改为新建</button>
      </div>

      <div class="row-gap" style="flex-wrap: wrap">
        <input
          v-model="pattern"
          class="input input-mono grow"
          style="min-width: 240px"
          placeholder="例：桜都字幕组.*某番剧.*\[(\d+)\]"
          @keyup.enter="test"
        />
        <input v-model="flags" class="input input-mono" style="width: 62px" title="修饰符" placeholder="iu" />
        <select v-model.number="epCapture" class="select input-sm" style="width: 128px" title="集号所在捕获组">
          <option :value="0">集号：自动猜</option>
          <option v-for="n in [1, 2, 3, 4]" :key="n" :value="n">集号：捕获组 {{ n }}</option>
        </select>
        <select v-model.number="priority" class="select input-sm" style="width: 108px" title="优先级">
          <option :value="10">优先级 10</option>
          <option :value="20">优先级 20</option>
          <option :value="30">优先级 30</option>
          <option :value="5">优先级 5</option>
        </select>
        <label class="check" title="保存后立即把命中条目加进 qBittorrent 下载队列">
          <input v-model="autoDownload" type="checkbox" />
          保存后自动下载命中条目
        </label>
        <button class="btn" :disabled="testing" @click="test">
          {{ testing ? '测试中…' : '测试' }}
        </button>
        <button class="btn btn-primary" :disabled="saving" @click="save">
          {{ saving ? '保存中…' : editingId ? '保存修改' : '保存规则' }}
        </button>
      </div>

      <div class="divider"></div>

      <div class="row-gap" style="flex-wrap: wrap">
        <input
          v-model="suggestFrom"
          class="input grow"
          style="min-width: 240px"
          placeholder="不确定怎么写？粘贴一条真实 RSS 标题，自动生成草稿"
        />
        <button class="btn btn-sm" :disabled="suggesting" @click="suggest">
          {{ suggesting ? '生成中…' : '生成草稿' }}
        </button>
      </div>
    </div>

    <!-- 命中结果 -->
    <div class="card">
      <div class="card-head">
        <div>
          <h3>命中预览</h3>
          <div class="sub">最近 {{ previewLimit }} 条 RSS 条目，仅展示命中的</div>
        </div>
        <div class="row-gap">
          <input
            v-model.number="previewLimit"
            class="input input-sm"
            style="width: 88px"
            type="number"
            min="1"
            max="2000"
            title="预览的 RSS 条目数，改后点「测试」生效"
          />
          <span v-if="result" class="tag" :class="hitCount > 0 ? 'tag-ok' : 'tag-warn'">
            命中 {{ hitCount }} / {{ totalCount }}
          </span>
        </div>
      </div>

      <div v-if="!result" class="empty">
        <div class="big">还没有测试结果</div>
        <div>填写正则后点「测试」，这里会实时显示哪些条目会被命中</div>
      </div>

      <template v-else>
        <div v-if="result.hint" class="alert mb-14" :class="hitCount > 0 ? 'alert-brand' : 'alert-warn'">
          {{ result.hint }}
        </div>

        <div style="max-height: 60vh; overflow: auto">
          <div
            v-for="(it, i) in hitItems"
            :key="it.id"
            class="regex-row hit"
          >
            <span class="idx">{{ i + 1 }}</span>
            <span class="tt">{{ it.title }}</span>
            <span class="cap">
              <span v-if="it.ep_no" class="tag tag-ok">集 {{ it.ep_no }}</span>
              <span v-for="(c, ci) in it.captures" :key="ci" class="tag tag-mute">${{ ci + 1 }}={{ c }}</span>
            </span>
          </div>
          <div v-if="!hitItems.length" class="empty">
            <div class="big">没有命中的条目</div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
