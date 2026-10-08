<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { api, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const busy = ref('')
const show = ref(false)
const generating = ref(false)
const justCreated = ref([])

const form = reactive({ count: 5, max_uses: 1, expire_days: 30, note: '' })

const enabled = computed(() => list.value.filter((c) => c.status === 1 && !c.expired))
const available = computed(() =>
  enabled.value.filter((c) => c.used_real < c.max_uses).length
)

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/invites')
    list.value = d.list || []
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

function openNew() {
  justCreated.value = []
  show.value = true
}

async function generate() {
  const n = Number(form.count)
  if (!Number.isInteger(n) || n < 1 || n > 200) {
    toast('数量需为 1-200 的整数', 'err')
    return
  }
  generating.value = true
  try {
    const d = await api.post('/api/admin/invites/batch', {
      count: n,
      max_uses: Number(form.max_uses) || 1,
      expire_days: Number(form.expire_days) || 0,
      note: form.note.trim(),
    })
    justCreated.value = d.codes || []
    toast(`已生成 ${d.count} 个邀请码`)
    await load()
  } catch (e) {
    toastErr(e)
  }
  generating.value = false
}

async function copyCode(c) {
  try {
    await navigator.clipboard.writeText(c)
    toast('已复制')
  } catch {
    const ta = document.createElement('textarea')
    ta.value = c
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    toast('已复制')
  }
}

async function copyAll() {
  if (!justCreated.value.length) return
  await copyCode(justCreated.value.join('\n'))
}

async function toggle(c) {
  busy.value = 'tg' + c.id
  try {
    const r = await api.post(`/api/admin/invites/${c.id}/toggle`)
    toast(r?.status === 1 ? '已启用' : '已作废')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function del(c) {
  if (!window.confirm(`删除邀请码 ${c.code}？`)) return
  busy.value = 'del' + c.id
  try {
    await api.del(`/api/admin/invites/${c.id}`)
    toast('已删除')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

function stateOf(c) {
  if (c.status !== 1) return { cn: '已作废', cls: 'tag-mute' }
  if (c.expired) return { cn: '已过期', cls: 'tag-warn' }
  if (c.used_real >= c.max_uses) return { cn: '已用尽', cls: 'tag-warn' }
  return { cn: '可用', cls: 'tag-ok' }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>邀请码</h1>
        <div class="small muted-3 mt-8">
          共 {{ list.length }} 个码，其中 {{ enabled.length }} 个生效、{{ available }} 个还有可用次数。
          注册开关在「设置 → 注册」里控制。
        </div>
      </div>
      <button class="btn btn-primary btn-sm" @click="openNew">生成邀请码</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 190px">邀请码</th>
              <th style="width: 92px">状态</th>
              <th style="width: 96px">已用 / 上限</th>
              <th style="width: 132px">过期时间</th>
              <th>备注</th>
              <th style="width: 132px">创建时间</th>
              <th style="width: 148px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in list" :key="c.id">
              <td>
                <button class="code-cell" @click="copyCode(c.code)" :title="'点击复制 ' + c.code">
                  {{ c.code }}
                </button>
              </td>
              <td>
                <span class="tag" :class="stateOf(c).cls">{{ stateOf(c).cn }}</span>
              </td>
              <td class="nowrap">
                <span :class="c.used_real >= c.max_uses ? 'muted-3' : 'strong'">{{ c.used_real }}</span>
                <span class="muted-3"> / {{ c.max_uses }}</span>
              </td>
              <td class="nowrap muted-3 small">
                {{ c.expires_at ? formatTime(c.expires_at) : '永久' }}
              </td>
              <td class="small muted break" style="max-width: 200px">{{ c.note || '—' }}</td>
              <td class="nowrap muted-3 small">{{ formatTime(c.created_at) }}</td>
              <td class="right nowrap">
                <button class="btn btn-sm btn-ghost" :disabled="!!busy" @click="toggle(c)">
                  {{ c.status === 1 ? '作废' : '启用' }}
                </button>
                <button class="btn btn-sm btn-danger" :disabled="!!busy" @click="del(c)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">还没有邀请码</div>
        <div>点右上角批量生成</div>
      </div>
    </div>

    <div v-if="show" class="mask" @click.self="show = false">
      <div class="modal">
        <div class="modal-head">
          <h3>生成邀请码</h3>
          <button class="btn btn-sm btn-ghost" @click="show = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="stack">
            <div class="form-grid">
              <div class="field">
                <label class="label">数量</label>
                <input v-model.number="form.count" class="input" type="number" min="1" max="200" />
              </div>
              <div class="field">
                <label class="label">每码可用次数</label>
                <input v-model.number="form.max_uses" class="input" type="number" min="1" max="100" />
              </div>
              <div class="field">
                <label class="label">有效天数（0 = 永久）</label>
                <input v-model.number="form.expire_days" class="input" type="number" min="0" max="3650" />
              </div>
            </div>
            <div class="field">
              <label class="label">备注（可选）</label>
              <input v-model="form.note" class="input" placeholder="如：给 A 组同学的批次" />
            </div>

            <div v-if="justCreated.length" class="field">
              <div class="between">
                <label class="label">本次生成（{{ justCreated.length }} 个）</label>
                <button class="btn btn-sm btn-ghost" @click="copyAll">全部复制</button>
              </div>
              <div class="code-block">{{ justCreated.join('\n') }}</div>
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="show = false">关闭</button>
          <button class="btn btn-primary" :disabled="generating" @click="generate">
            {{ generating ? '生成中…' : '生成' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.code-cell {
  font-family: var(--mono);
  font-size: 13px;
  letter-spacing: .5px;
  color: var(--brand-dark);
  background: var(--brand-soft);
  border: 1px solid var(--brand-line);
  border-radius: 4px;
  padding: 3px 9px;
  cursor: pointer;
}
.code-cell:hover { background: #dbe9f8; }
</style>
