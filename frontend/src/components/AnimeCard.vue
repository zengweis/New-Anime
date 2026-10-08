<script setup>
import { computed, ref } from 'vue'
import { SEASON_CN } from '../api'

const props = defineProps({
  anime: { type: Object, required: true },
  showProgress: { type: Boolean, default: false },
})

const imgErr = ref(false)

const title = computed(() => props.anime.title_cn || props.anime.title || '未命名')

const meta = computed(() => {
  const a = props.anime
  const bits = []
  if (a.year) bits.push(a.year)
  if (a.season) bits.push(SEASON_CN[a.season] + '季')
  if (a.ep_count) bits.push(a.ep_count + ' 集')
  return bits.join(' · ')
})

const progressText = computed(() => {
  const a = props.anime
  if (!props.showProgress) return ''
  const w = Number(a.watched_ep) || 0
  const total = Number(a.ep_count) || 0
  if (w <= 0) return '尚未观看'
  return total > 0 ? `看到 ${w} / ${total}` : `看到 ${w}`
})
</script>

<template>
  <router-link :to="{ name: 'anime', params: { id: anime.id } }" class="anime-card">
    <div class="cover">
      <img
        v-if="anime.cover && !imgErr"
        :src="anime.cover"
        :alt="title"
        loading="lazy"
        @error="imgErr = true"
      />
      <span v-else class="ph">{{ title.slice(0, 6) }}</span>

      <span v-if="anime.has_new" class="badge badge-new">更新</span>
      <span v-else-if="anime.ep_count" class="badge">{{ anime.ep_count }} 集</span>
    </div>

    <div class="body">
      <div class="t" :title="title">{{ title }}</div>
      <div class="m ellip">
        <template v-if="progressText">{{ progressText }}</template>
        <template v-else>{{ meta || '—' }}</template>
      </div>
    </div>
  </router-link>
</template>
