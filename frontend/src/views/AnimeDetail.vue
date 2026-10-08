<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { api, formatSize, epLabel, formatDuration, SEASON_CN } from '../api'
import { auth } from '../store/auth'
import { toast, toastErr } from '../store/ui'

const route = useRoute()

const loading = ref(true)
const anime = ref(null)
const episodes = ref([])
const busyFav = ref(false)
const imgErr = ref(false)

const title = computed(() => anime.value?.title_cn || anime.value?.title || '')
const alias = computed(() => {
  const a = anime.value
  if (!a) return ''
  const parts = []
  if (a.title_cn && a.title && a.title_cn !== a.title) parts.push(a.title)
  if (a.alias) parts.push(a.alias)
  return parts.join(' / ')
})

const metaLine = computed(() => {
  const a = anime.value
  if (!a) return ''
  const bits = []
  if (a.year) bits.push(a.year + ' 年')
  if (a.season) bits.push(SEASON_CN[a.season] + '季')
  bits.push(a.status === 1 ? '已完结' : '连载中')
  return bits.join(' · ')
})

const watchNext = computed(() => {
  const list = episodes.value
  if (!list.length) return null
  const unfinished = list.find((e) => !e.progress?.finished)
  return unfinished || list[0]
})

async function load() {
  loading.value = true
  try {
    const d = await api.get(`/api/animes/${route.params.id}`)
    anime.value = d.anime
    episodes.value = d.episodes || []
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

async function toggleFav() {
  if (!auth.user) {
    toast('请先登录', 'err')
    return
  }
  busyFav.value = true
  try {
    const on = anime.value.is_fav
    const d = on
      ? await api.del(`/api/me/favorites/${anime.value.id}`)
      : await api.post(`/api/me/favorites/${anime.value.id}`)
    anime.value.is_fav = d.is_fav
    toast(d.is_fav ? '已加入追番' : '已取消追番')
  } catch (e) {
    toastErr(e)
  }
  busyFav.value = false
}

onMounted(load)
</script>

<template>
  <div class="wrap page">
    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else-if="anime">
      <!-- 头部信息 -->
      <div class="row-gap" style="align-items: flex-start; gap: 22px; flex-wrap: wrap">
        <div style="width: 190px; flex: none">
          <div class="anime-card" style="cursor: default">
            <div class="cover">
              <img v-if="anime.cover && !imgErr" :src="anime.cover" :alt="title" @error="imgErr = true" />
              <span v-else class="ph">{{ title.slice(0, 6) }}</span>
            </div>
          </div>
        </div>

        <div class="grow" style="min-width: 260px">
          <h1>{{ title }}</h1>
          <div v-if="alias" class="muted small mt-8 break">{{ alias }}</div>
          <div class="muted-3 small mt-8">{{ metaLine }}</div>

          <div class="wrapflex mt-14">
            <span class="tag">{{ episodes.length }} 集</span>
            <span v-if="anime.last_ep" class="tag">最新 {{ epLabel(anime.last_ep) }}</span>
            <span v-if="anime.updated_at" class="tag tag-mute">更新于 {{ String(anime.updated_at).slice(0, 10) }}</span>
          </div>

          <div class="wrapflex mt-14">
            <router-link
              v-if="watchNext"
              :to="{ name: 'play', params: { id: watchNext.id } }"
              class="btn btn-primary"
            >{{ episodes.some((e) => e.progress) ? '继续播放' : '开始观看' }}</router-link>
            <button class="btn" :disabled="busyFav" @click="toggleFav">
              {{ anime.is_fav ? '已追番' : '+ 追番' }}
            </button>
          </div>

          <p v-if="anime.summary" class="muted small mt-14" style="max-width: 640px">{{ anime.summary }}</p>
        </div>
      </div>

      <!-- 集数 -->
      <section class="mt-22">
        <div class="between mb-8">
          <h2>选集</h2>
          <span class="small muted-3">共 {{ episodes.length }} 集</span>
        </div>

        <div v-if="episodes.length" class="ep-grid">
          <router-link
            v-for="e in episodes"
            :key="e.id"
            :to="{ name: 'play', params: { id: e.id } }"
            class="ep"
            :class="{ watched: e.progress?.finished }"
            :title="e.title || ''"
          >
            <span class="dot"></span>
            <span class="no">{{ epLabel(e.ep_no) }}</span>
            <span class="grow ellip muted-3 tiny">
              <template v-if="e.progress && !e.progress.finished">
                {{ formatDuration(e.progress.position_sec) }}
              </template>
              <template v-else>{{ e.resolution || '' }}</template>
            </span>
          </router-link>
        </div>

        <div v-else class="card empty">
          <div class="big">还没有可播放的集数</div>
          <div>后台匹配规则命中并下载完成后会自动出现</div>
        </div>
      </section>
    </template>

    <div v-else class="card empty">
      <div class="big">番剧不存在</div>
    </div>
  </div>
</template>
