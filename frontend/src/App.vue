<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth, logout } from './store/auth'
import { site } from './store/site'
import { ui, toast } from './store/ui'

const route = useRoute()
const router = useRouter()

const plain = computed(() => !!route.meta.plain)
const nav = [
  { name: 'home', label: '首页' },
  { name: 'browse', label: '番剧库' },
  { name: 'me', label: '我的追番' },
]

const kw = ref('')
watch(() => route.query.keyword, (v) => { kw.value = v || '' })

function doSearch() {
  const q = kw.value.trim()
  router.push({ name: 'browse', query: q ? { keyword: q } : {} })
}

function doLogout() {
  logout()
  toast('已退出登录')
  router.push({ name: 'home' })
}
</script>

<template>
  <!-- 登录/注册这类整页布局 -->
  <template v-if="plain">
    <router-view />
  </template>

  <template v-else>
    <header class="topbar">
      <div class="topbar-inner">
        <router-link to="/" class="logo">{{ site.name }}</router-link>

        <nav class="nav">
          <router-link
            v-for="n in nav"
            :key="n.name"
            :to="{ name: n.name }"
            :class="{ on: route.name === n.name }"
          >{{ n.label }}</router-link>
        </nav>

        <div class="spacer"></div>

        <form class="row-gap" @submit.prevent="doSearch" style="width: 210px">
          <input v-model="kw" class="input input-sm" type="search" placeholder="搜索番剧" />
        </form>

        <template v-if="auth.user">
          <router-link
            v-if="auth.user.role === 'admin'"
            :to="{ name: 'adminOverview' }"
            class="btn btn-sm"
          >管理后台</router-link>
          <div class="row-gap">
            <span class="small muted">{{ auth.user.nickname || auth.user.username }}</span>
            <button class="btn btn-sm btn-ghost" @click="doLogout">退出</button>
          </div>
        </template>
        <template v-else>
          <router-link :to="{ name: 'login' }" class="btn btn-sm">登录</router-link>
          <router-link :to="{ name: 'register' }" class="btn btn-sm btn-primary">注册</router-link>
        </template>
      </div>
    </header>

    <main>
      <router-view v-slot="{ Component }">
        <component :is="Component" />
      </router-view>
    </main>

    <footer class="center muted-3 tiny" style="padding: 28px 20px 40px">
      {{ site.name }} · {{ site.tagline }}
    </footer>
  </template>

  <div class="toast-wrap">
    <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="t.type === 'err' ? 'toast-err' : 'toast-ok'">
      {{ t.msg }}
    </div>
  </div>
</template>
