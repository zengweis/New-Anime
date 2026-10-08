<script setup>
import { ref, onMounted } from 'vue'
import { api, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const loading = ref(true)
const list = ref([])
const busy = ref('')

const show = ref(false)
const editing = ref(null)
const form = ref({})

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/feeds')
    list.value = d.list || []
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

function openNew() {
  editing.value = null
  form.value = { name: '', url: 'https://dmhy.org/topics/rss/sort_id/2/rss.xml', enabled: true, auto_down: false, filter_re: '', exclude_re: '', min_res: '1080' }
  show.value = true
}

function openEdit(f) {
  editing.value = f
  form.value = {
    name: f.name || '',
    url: f.url || '',
    enabled: !!Number(f.enabled),
    auto_down: !!Number(f.auto_down),
    filter_re: f.filter_re || '',
    exclude_re: f.exclude_re || '',
    min_res: f.min_res || '1080',
  }
  show.value = true
}

async function submit() {
  if (!form.value.name.trim() || !form.value.url.trim()) {
    toast('名称和地址不能为空', 'err')
    return
  }
  busy.value = 'save'
  try {
    if (editing.value) {
      await api.put(`/api/admin/feeds/${editing.value.id}`, form.value)
      toast('已保存')
    } else {
      await api.post('/api/admin/feeds', form.value)
      toast('已添加')
    }
    show.value = false
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function toggle(f, field) {
  try {
    await api.put(`/api/admin/feeds/${f.id}`, { [field]: !Number(f[field]) })
    await load()
  } catch (e) {
    toastErr(e)
  }
}

async function fetchOne(f) {
  busy.value = 'fetch' + f.id
  try {
    const r = await api.post('/api/admin/feeds/fetch', { feed_id: f.id })
    toast(`抓取 ${r.fetch.fetched} 条，新增 ${r.fetch.new} 条，加种 ${r.auto?.added ?? 0}`)
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

async function del(f) {
  if (!window.confirm(`删除订阅源「${f.name}」？`)) return
  try {
    await api.del(`/api/admin/feeds/${f.id}`)
    toast('已删除')
    await load()
  } catch (e) {
    toastErr(e)
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>订阅源</h1>
        <div class="small muted-3 mt-8">
          dmhy 的 RSS 地址。开启「自动下载」后，命中匹配规则的条目会自动加进 qBittorrent。
        </div>
      </div>
      <button class="btn btn-primary btn-sm" @click="openNew">添加订阅源</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <div v-else class="card card-tight">
      <div v-if="list.length" class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th>名称</th>
              <th>地址</th>
              <th style="width: 72px">启用</th>
              <th style="width: 96px">自动下载</th>
              <th style="width: 86px">最低画质</th>
              <th style="width: 118px">上次抓取</th>
              <th style="width: 74px">条数</th>
              <th style="width: 158px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in list" :key="f.id">
              <td class="nowrap">{{ f.name }}</td>
              <td class="tiny muted break" style="max-width: 300px">{{ f.url }}</td>
              <td>
                <button class="btn btn-sm btn-ghost" @click="toggle(f, 'enabled')">
                  <span class="tag" :class="Number(f.enabled) ? 'tag-ok' : 'tag-mute'">
                    {{ Number(f.enabled) ? '开' : '关' }}
                  </span>
                </button>
              </td>
              <td>
                <button class="btn btn-sm btn-ghost" @click="toggle(f, 'auto_down')">
                  <span class="tag" :class="Number(f.auto_down) ? 'tag-brand' : 'tag-mute'">
                    {{ Number(f.auto_down) ? '开' : '关' }}
                  </span>
                </button>
              </td>
              <td class="nowrap">{{ f.min_res || '—' }}</td>
              <td class="nowrap muted-3 small">{{ formatTime(f.last_fetch) }}</td>
              <td>{{ f.last_count || 0 }}</td>
              <td class="right nowrap">
                <button class="btn btn-sm" :disabled="!!busy" @click="fetchOne(f)">
                  {{ busy === 'fetch' + f.id ? '抓取中…' : '抓取' }}
                </button>
                <button class="btn btn-sm btn-ghost" @click="openEdit(f)">编辑</button>
                <button class="btn btn-sm btn-danger" @click="del(f)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <div class="big">还没有订阅源</div>
        <div>点右上角添加，或执行 php bin/seed.php 初始化默认源</div>
      </div>
    </div>

    <div v-if="show" class="mask" @click.self="show = false">
      <div class="modal">
        <div class="modal-head">
          <h3>{{ editing ? '编辑订阅源' : '添加订阅源' }}</h3>
          <button class="btn btn-sm btn-ghost" @click="show = false">关闭</button>
        </div>
        <div class="modal-body">
          <div class="stack">
            <div class="field">
              <label class="label">名称</label>
              <input v-model="form.name" class="input" placeholder="dmhy 動畫" />
            </div>
            <div class="field">
              <label class="label">RSS 地址</label>
              <input v-model="form.url" class="input input-mono" placeholder="https://dmhy.org/topics/rss/sort_id/2/rss.xml" />
              <span class="tiny muted-3">
                動畫分类：/topics/rss/sort_id/2/rss.xml · 关键词：/topics/rss/rss.xml?keyword=xxx
              </span>
            </div>
            <div class="form-grid">
              <div class="field">
                <label class="label">最低画质</label>
                <select v-model="form.min_res" class="select">
                  <option value="2160">2160P</option>
                  <option value="1080">1080P</option>
                  <option value="720">720P</option>
                  <option value="">不限</option>
                </select>
              </div>
              <div class="field">
                <label class="label">开关</label>
                <div class="row-gap" style="height: 36px">
                  <label class="check"><input type="checkbox" v-model="form.enabled" /> 启用</label>
                  <label class="check"><input type="checkbox" v-model="form.auto_down" /> 自动下载</label>
                </div>
              </div>
            </div>
            <div class="field">
              <label class="label">包含正则（可选）</label>
              <input v-model="form.filter_re" class="input input-mono" placeholder="标题必须匹配，留空则不过滤" />
            </div>
            <div class="field">
              <label class="label">排除正则（可选）</label>
              <input v-model="form.exclude_re" class="input input-mono" placeholder="标题命中则丢弃，如：招募|合集" />
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" @click="show = false">取消</button>
          <button class="btn btn-primary" :disabled="busy === 'save'" @click="submit">
            {{ busy === 'save' ? '保存中…' : '保存' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
