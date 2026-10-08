<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()

const groups = [
  {
    title: '内容',
    items: [
      { name: 'adminOverview', label: '总览' },
      { name: 'adminAnimes', label: '番剧管理' },
      { name: 'adminRules', label: '匹配规则' },
      { name: 'adminWishes', label: '许愿墙' },
      { name: 'adminDanmaku', label: '弹幕' },
    ],
  },
  {
    title: '采集',
    items: [
      { name: 'adminFeeds', label: '订阅源' },
      { name: 'adminItems', label: '条目池' },
    ],
  },
  {
    title: '运行',
    items: [
      { name: 'adminDownloads', label: '下载任务' },
      { name: 'adminUploads', label: '上传任务' },
    ],
  },
  {
    title: '系统',
    items: [
      { name: 'adminUsers', label: '用户' },
      { name: 'adminInvites', label: '邀请码' },
      { name: 'adminSettings', label: '设置' },
    ],
  },
]
</script>

<template>
  <div class="wrap-wide page">
    <div class="admin-shell">
      <aside class="admin-side">
        <template v-for="g in groups" :key="g.title">
          <div class="grp">{{ g.title }}</div>
          <router-link
            v-for="it in g.items"
            :key="it.name"
            :to="{ name: it.name }"
            :class="{ on: route.name === it.name }"
          >{{ it.label }}</router-link>
        </template>
      </aside>

      <div class="admin-main">
        <router-view />
      </div>
    </div>
  </div>
</template>
