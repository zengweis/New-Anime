<script setup>
import { ref, onMounted } from 'vue'
import { api, formatTime } from '../api'
import { auth } from '../store/auth'
import { toast, toastErr } from '../store/ui'

const wishes = ref([])
const loading = ref(true)
const name = ref('')
const note = ref('')
const submitting = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/wishes')
    wishes.value = d.list || []
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

async function submit() {
  if (!auth.user) {
    toast('请先登录再许愿', 'err')
    return
  }
  if (!name.value.trim()) {
    toast('请填写想看的动漫名称', 'err')
    return
  }
  submitting.value = true
  try {
    await api.post('/api/wishes', { anime_name: name.value.trim(), note: note.value.trim() })
    toast('许愿成功，愿它早日上线')
    name.value = ''
    note.value = ''
    await load()
  } catch (e) {
    toastErr(e)
  }
  submitting.value = false
}

const statusClass = (s) => (s === 1 ? 'tag-ok' : s === 2 ? 'tag-warn' : s === 3 ? 'tag-mute' : 'tag-brand')

// 星星位置/大小/闪烁延迟 用确定性伪随机（基于 index），避免每次渲染跳动
function starStyle(i) {
  const seed = (i * 2654435761) % 1000
  const x = seed % 100
  const y = (i * 73) % 100
  const size = 1 + (seed % 3)
  const delay = (seed % 40) / 10
  const dur = 2 + (seed % 30) / 10
  return {
    left: x + '%',
    top: y + '%',
    width: size + 'px',
    height: size + 'px',
    animationDelay: delay + 's',
    animationDuration: dur + 's',
  }
}

onMounted(load)
</script>

<template>
  <div class="wrap page">
    <!-- 星空 Hero + 许愿框 -->
    <div class="wish-hero">
      <div class="stars" aria-hidden="true">
        <span v-for="i in 40" :key="i" class="star" :style="starStyle(i)"></span>
      </div>
      <span class="meteor m1"></span>
      <span class="meteor m2"></span>
      <span class="meteor m3"></span>

      <h1 class="hero-title">许愿墙</h1>
      <p class="hero-sub">想看哪部番？在这里许个愿，说不定下一部入库的就是它。</p>

      <div class="wish-form">
        <input
          v-model="name"
          class="wish-input"
          placeholder="输入想看的动漫名称…"
          @keyup.enter="submit"
        />
        <input v-model="note" class="wish-input wish-note" placeholder="补充说明（可选）" @keyup.enter="submit" />
        <button class="wish-btn" :disabled="submitting" @click="submit">
          {{ submitting ? '许愿中…' : '许愿' }}
        </button>
      </div>
      <div v-if="!auth.user" class="hero-tip">未登录也可围观，登录后才能许愿</div>
    </div>

    <!-- 卡片墙 -->
    <div v-if="loading" class="loading" style="padding: 40px 0"><span class="spin"></span> 加载中</div>

    <template v-else>
      <div class="between" style="margin: 22px 0 14px">
        <h2>大家的愿望</h2>
        <span class="small muted-3">共 {{ wishes.length }} 条</span>
      </div>

      <div v-if="wishes.length" class="wish-grid">
        <div
          v-for="(w, i) in wishes"
          :key="w.id"
          class="wish-card"
          :class="{ done: w.status === 1, wip: w.status === 2, rejected: w.status === 3 }"
          :style="{ animationDelay: (Math.min(i, 20) * 40) + 'ms' }"
        >
          <div class="wish-name">{{ w.anime_name }}</div>
          <div v-if="w.note" class="wish-note-text">{{ w.note }}</div>
          <div class="wish-meta">
            <span class="tag" :class="statusClass(w.status)">{{ w.status_cn }}</span>
            <span class="tiny muted-3">
              {{ w.is_mine ? '我' : w.user.nickname }} · {{ formatTime(w.created_at) }}
            </span>
          </div>
          <div v-if="w.status !== 0 && w.result_note" class="wish-result">{{ w.result_note }}</div>
        </div>
      </div>

      <div v-else class="card empty" style="margin-top: 20px">
        <div class="big">还没有愿望</div>
        <div>来做第一个许愿的人吧</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ---- 星空 Hero ---- */
.wish-hero {
  position: relative;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background: #14294a;
  padding: 40px 32px 36px;
  color: #fff;
}
.hero-title { font-size: 26px; font-weight: 600; letter-spacing: 1px; }
.hero-sub { margin-top: 8px; color: #b9cbe2; }

.stars { position: absolute; inset: 0; pointer-events: none; }
.star {
  position: absolute;
  background: #dbe7f5;
  border-radius: 50%;
  opacity: 0.7;
  animation-name: twinkle;
  animation-iteration-count: infinite;
  animation-timing-function: ease-in-out;
}
@keyframes twinkle {
  0%, 100% { opacity: 0.15; }
  50% { opacity: 0.9; }
}

.meteor {
  position: absolute;
  width: 120px;
  height: 2px;
  background: linear-gradient(to left, #7db6f0, transparent);
  border-radius: 2px;
  opacity: 0;
  transform: rotate(-24deg);
  animation: shoot 6s ease-in infinite;
}
.m1 { top: 8%; left: 60%; }
.m2 { top: 22%; left: 78%; animation-delay: 2.4s; }
.m3 { top: 40%; left: 90%; animation-delay: 4.2s; }
@keyframes shoot {
  0% { opacity: 0; transform: translate(0, 0) rotate(-24deg); }
  3% { opacity: 1; }
  12% { opacity: 0; transform: translate(-260px, 150px) rotate(-24deg); }
  100% { opacity: 0; }
}

/* ---- 许愿表单 ---- */
.wish-form { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
.wish-input {
  flex: 1; min-width: 200px;
  padding: 11px 14px;
  border-radius: var(--radius);
  border: 1px solid rgba(255, 255, 255, 0.18);
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
  font-size: 14px;
  outline: none;
}
.wish-input::placeholder { color: #8fa6c4; }
.wish-input:focus { border-color: #5b9ad6; background: rgba(255, 255, 255, 0.14); }
.wish-note { flex: 0.5; min-width: 160px; }
.wish-btn {
  padding: 11px 26px;
  border: none; border-radius: var(--radius);
  background: #5b9ad6;
  color: #fff; font-size: 14px; font-weight: 500;
  cursor: pointer;
  transition: background 0.15s ease;
}
.wish-btn:hover { background: #6fabe0; }
.wish-btn:disabled { opacity: 0.6; cursor: default; }
.hero-tip { margin-top: 14px; font-size: 12px; color: #7d95b5; }

/* ---- 卡片墙 ---- */
.wish-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 14px;
}
.wish-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 16px;
  opacity: 0;
  animation: rise 0.45s ease forwards;
  transition: transform 0.15s ease, border-color 0.15s ease;
}
.wish-card:hover { transform: translateY(-3px); border-color: var(--brand-line); }
.wish-card.done { border-color: var(--ok); }
.wish-card.wip { border-color: var(--warn); }
.wish-card.rejected { border-color: var(--line-strong); opacity: 0.75; }
@keyframes rise {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.wish-name { font-size: 15px; font-weight: 600; word-break: break-all; }
.wish-note-text { margin-top: 6px; font-size: 13px; color: var(--text-2); }
.wish-meta { margin-top: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.wish-result {
  margin-top: 10px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  font-size: 12px; color: var(--text-2);
}
</style>
