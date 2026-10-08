<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { auth } from '../store/auth'
import { toastErr } from '../store/ui'
import AnimeCard from '../components/AnimeCard.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const list = ref([])
const total = ref(0)
const pages = ref(1)
const meta = ref({ years: [] })

const filters = ref({
  keyword: '',
  year: '',
  season: '',
  order: 'updated',
  fav: false,
  page: 1,
})

function syncFromRoute() {
  const q = route.query
  filters.value = {
    keyword: q.keyword || '',
    year: q.year || '',
    season: q.season || '',
    order: q.order || 'updated',
    fav: q.fav === '1',
    page: Number(q.page) || 1,
  }
}

async function load() {
  loading.value = true
  try {
    const f = filters.value
    const d = await api.get('/api/animes', {
      keyword: f.keyword,
      year: f.year,
      season: f.season,
      order: f.order,
      fav: f.fav ? 1 : '',
      page: f.page,
      per_page: 30,
    })
    list.value = d.list || []
    total.value = d.total || 0
    pages.value = d.pages || 1
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

function apply(patch = {}) {
  const next = { ...filters.value, ...patch }
  const q = {}
  if (next.keyword) q.keyword = next.keyword
  if (next.year) q.year = next.year
  if (next.season) q.season = next.season
  if (next.order && next.order !== 'updated') q.order = next.order
  if (next.fav) q.fav = '1'
  if (next.page > 1) q.page = String(next.page)
  router.push({ name: 'browse', query: q })
}

watch(() => route.query, () => {
  syncFromRoute()
  load()
})

onMounted(async () => {
  syncFromRoute()
  await load()
  try {
    meta.value = await api.get('/api/animes/meta')
  } catch {
    /* 忽略 */
  }
})

const pageList = computed(() => {
  const cur = filters.value.page
  const max = pages.value
  const out = []
  let s = Math.max(1, cur - 2)
  let e = Math.min(max, s + 4)
  s = Math.max(1, e - 4)
  for (let i = s; i <= e; i++) out.push(i)
  return out
})
</script>

<template>
  <div class="wrap page">
    <div class="between mb-14">
      <h1>番剧库</h1>
      <span class="small muted-3">共 {{ total }} 部</span>
    </div>

    <!-- 筛选条 -->
    <div class="card card-tight mb-14">
      <div class="wrapflex">
        <input
          v-model="filters.keyword"
          class="input input-sm"
          style="width: 210px"
          placeholder="搜索标题 / 原名"
          @keyup.enter="apply({ page: 1 })"
        />
        <select v-model="filters.year" class="select input-sm" style="width: 116px" @change="apply({ page: 1 })">
          <option value="">全部年份</option>
          <option v-for="y in meta.years" :key="y.year" :value="y.year">
            {{ y.year }} ({{ y.count }})
          </option>
        </select>
        <select v-model="filters.season" class="select input-sm" style="width: 108px" @change="apply({ page: 1 })">
          <option value="">全部季度</option>
          <option value="1">春</option>
          <option value="2">夏</option>
          <option value="3">秋</option>
          <option value="4">冬</option>
        </select>
        <select v-model="filters.order" class="select input-sm" style="width: 116px" @change="apply({ page: 1 })">
          <option value="updated">最近更新</option>
          <option value="year">按年份</option>
          <option value="title">按标题</option>
          <option value="hot">按集数</option>
        </select>
        <label v-if="auth.user" class="check">
          <input type="checkbox" v-model="filters.fav" @change="apply({ page: 1 })" />
          只看我的追番
        </label>
        <div class="spacer"></div>
        <button class="btn btn-sm" @click="apply({ page: 1 })">应用</button>
      </div>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else>
      <div v-if="list.length" class="grid">
        <AnimeCard v-for="a in list" :key="a.id" :anime="a" />
      </div>

      <div v-else class="card empty">
        <div class="big">没有匹配的番剧</div>
        <div>换个筛选条件试试</div>
      </div>

      <div v-if="pages > 1" class="pager">
        <button class="btn btn-sm" :disabled="filters.page <= 1" @click="apply({ page: filters.page - 1 })">上一页</button>
        <button
          v-for="p in pageList"
          :key="p"
          class="btn btn-sm"
          :class="{ on: p === filters.page }"
          @click="apply({ page: p })"
        >{{ p }}</button>
        <button class="btn btn-sm" :disabled="filters.page >= pages" @click="apply({ page: filters.page + 1 })">下一页</button>
        <span class="info">{{ filters.page }} / {{ pages }}</span>
      </div>
    </template>
  </div>
</template>
