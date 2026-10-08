import { createRouter, createWebHistory } from 'vue-router'
import { auth, loadUser } from '../store/auth'

const routes = [
  { path: '/', name: 'home', component: () => import('../views/Home.vue') },
  { path: '/browse', name: 'browse', component: () => import('../views/Browse.vue') },
  { path: '/anime/:id', name: 'anime', component: () => import('../views/AnimeDetail.vue') },
  {
    path: '/play/:id',
    name: 'play',
    component: () => import('../views/Player.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/me',
    name: 'me',
    component: () => import('../views/Me.vue'),
    meta: { requiresAuth: true },
  },
  { path: '/wish', name: 'wish', component: () => import('../views/WishWall.vue') },

  { path: '/login', name: 'login', component: () => import('../views/Login.vue'), meta: { guestOnly: true, plain: true } },
  { path: '/register', name: 'register', component: () => import('../views/Register.vue'), meta: { guestOnly: true, plain: true } },
  { path: '/verify-email', name: 'verifyEmail', component: () => import('../views/VerifyEmail.vue'), meta: { plain: true } },
  { path: '/forgot-password', name: 'forgot', component: () => import('../views/ForgotPassword.vue'), meta: { guestOnly: true, plain: true } },
  { path: '/reset-password', name: 'reset', component: () => import('../views/ResetPassword.vue'), meta: { plain: true } },

  {
    path: '/admin',
    component: () => import('../views/admin/AdminLayout.vue'),
    meta: { requiresAdmin: true },
    children: [
      { path: '', name: 'adminOverview', component: () => import('../views/admin/Overview.vue') },
      { path: 'animes', name: 'adminAnimes', component: () => import('../views/admin/Animes.vue') },
      { path: 'rules', name: 'adminRules', component: () => import('../views/admin/Rules.vue') },
      { path: 'feeds', name: 'adminFeeds', component: () => import('../views/admin/Feeds.vue') },
      { path: 'items', name: 'adminItems', component: () => import('../views/admin/Items.vue') },
      { path: 'wishes', name: 'adminWishes', component: () => import('../views/admin/AdminWishes.vue') },
      { path: 'danmaku', name: 'adminDanmaku', component: () => import('../views/admin/Danmaku.vue') },
      { path: 'downloads', name: 'adminDownloads', component: () => import('../views/admin/Downloads.vue') },
      { path: 'uploads', name: 'adminUploads', component: () => import('../views/admin/S3Tasks.vue') },
      { path: 'users', name: 'adminUsers', component: () => import('../views/admin/Users.vue') },
      { path: 'invites', name: 'adminInvites', component: () => import('../views/admin/Invites.vue') },
      { path: 'settings', name: 'adminSettings', component: () => import('../views/admin/Settings.vue') },
    ],
  },

  { path: '/:pathMatch(.*)*', name: 'notFound', component: () => import('../views/NotFound.vue') },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, from, saved) {
    return saved || { top: 0 }
  },
})

router.beforeEach(async (to) => {
  if (!auth.ready) {
    await loadUser()
  }

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.requiresAdmin && auth.user?.role !== 'admin') {
    return { name: 'home' }
  }
  if (to.meta.guestOnly && auth.user) {
    return { name: 'home' }
  }
  return true
})

export default router
