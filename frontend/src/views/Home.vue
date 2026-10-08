<script setup>
import { ref, onMounted, computed } from 'vue'
import { api, formatDuration, epLabel } from '../api'
import { auth } from '../store/auth'
import { toastErr } from '../store/ui'
import AnimeCard from '../components/AnimeCard.vue'

const loading = ref(true)
const home = ref({ recent: [], fav_updates: [], stats: { anime: 0, episode: 0 } })
const cont = ref([])

const hasAnything = computed(() => home.value.recent.length > 0)

onMounted(async () => {
  try {
    home.value = await api.get('/api/home')
  } catch (e) {
    toastErr(e)
  }

  if (auth.user) {
    try {
      const d = await api.get('/api/me/continue')
      cont.value = d.list || []
    } catch {
      /* 忽略 */
    }
  }
  loading.value = false
})
</script>

<template>
  <div class="wrap page">
    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else>
      <!-- 继续观看 -->
      <section v-if="cont.length" class="mb-14">
        <div class="between mb-8">
          <h2>继续观看</h2>
        </div>
        <div class="table-scroll">
          <table class="table">
            <tbody>
              <tr v-for="c in cont" :key="c.episode_id">
                <td style="width: 46%">
                  <router-link :to="{ name: 'anime', params: { id: c.anime_id } }">
                    {{ c.title_cn || c.title }}
                  </router-link>
                  <span class="muted-3 small"> · 第 {{ epLabel(c.ep_no) }} 集</span>
                </td>
                <td class="muted-3 small nowrap" style="width: 22%">
                  上次看到 {{ formatDuration(c.position_sec) }}
                </td>
                <td class="right">
                  <router-link
                    :to="{ name: 'play', params: { id: c.episode_id } }"
                    class="btn btn-sm btn-primary"
                  >继续播放</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 追番更新 -->
      <section v-if="home.fav_updates?.length" class="mb-14">
        <div class="between mb-8">
          <h2>我的追番</h2>
          <router-link :to="{ name: 'me' }" class="small">全部追番 →</router-link>
        </div>
        <div class="grid">
          <AnimeCard v-for="a in home.fav_updates" :key="a.id" :anime="a" show-progress />
        </div>
      </section>

      <!-- 最近入库 -->
      <section>
        <div class="between mb-8">
          <h2>最近入库</h2>
          <span class="small muted-3">
            共 {{ home.stats.anime }} 部 · {{ home.stats.episode }} 集
          </span>
        </div>

        <div v-if="hasAnything" class="grid">
          <AnimeCard v-for="a in home.recent" :key="a.id" :anime="a" />
        </div>

        <div v-else class="card empty">
          <div class="big">库里还没有番剧</div>
          <div>在后台添加订阅源并配置匹配规则后，新番会自动出现在这里</div>
          <router-link
            v-if="auth.user?.role === 'admin'"
            :to="{ name: 'adminFeeds' }"
            class="btn btn-primary mt-14"
          >去后台配置</router-link>
        </div>
      </section>
    </template>
  </div>
</template>
