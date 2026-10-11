<script setup>
import { ref, onMounted } from 'vue'
import { api, formatSize, formatTime } from '../../api'
import { toast, toastErr } from '../../store/ui'

const d = ref(null)
const loading = ref(true)
const busy = ref('')

async function load() {
  loading.value = true
  try {
    d.value = await api.get('/api/admin/overview')
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

async function act(kind) {
  busy.value = kind
  try {
    let r
    if (kind === 'fetch') r = await api.post('/api/admin/feeds/fetch')
    if (kind === 'sync') r = await api.post('/api/admin/sync')
    if (kind === 'refresh') r = await api.post('/api/admin/sync/refresh')
    toast(r?.msg || '完成')
    await load()
  } catch (e) {
    toastErr(e)
  }
  busy.value = ''
}

/** 本机 / 远程 在合计里各占多少（用于对比条宽度，最小 2% 保证可见） */
function share(bytes) {
  const total = d.value?.storage?.total || 0
  if (!total || !bytes) return 0
  return Math.max(2, Math.min(100, (bytes / total) * 100)).toFixed(1)
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <h1>总览</h1>
      <button class="btn btn-sm" :disabled="loading" @click="load">刷新</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else-if="d">
      <div class="stat-grid mb-14">
        <div class="stat"><div class="k">番剧</div><div class="v">{{ d.stats.anime }}</div></div>
        <div class="stat"><div class="k">集数</div><div class="v">{{ d.stats.episode }}</div></div>
        <div class="stat">
          <div class="k">本机占用</div>
          <div class="v">{{ formatSize(d.storage.local.bytes) }}</div>
          <div class="tiny muted-3">视频 {{ formatSize(d.storage.local.video) }} · 字幕 {{ formatSize(d.storage.local.subs) }}</div>
        </div>
        <div class="stat">
          <div class="k">
            远程占用
            <span v-if="!d.storage.remote.enabled" class="tag tag-warn" style="margin-left: 4px">未启用</span>
          </div>
          <div class="v">{{ d.storage.remote.enabled ? formatSize(d.storage.remote.bytes) : '—' }}</div>
          <div class="tiny muted-3">
            {{ d.storage.remote.enabled ? d.storage.remote.objects + ' 个对象 · ' + (d.storage.remote.prefix || '') : '对象存储未开启' }}
          </div>
        </div>
        <div class="stat"><div class="k">用户</div><div class="v">{{ d.stats.users }}<small v-if="d.stats.users_pend">+{{ d.stats.users_pend }}待处理</small></div></div>
        <div class="stat"><div class="k">待处理条目</div><div class="v">{{ d.stats.items_todo }}</div></div>
        <div class="stat"><div class="k">启用规则</div><div class="v">{{ d.stats.rules }}</div></div>
      </div>

      <div class="row-gap mb-14" style="flex-wrap: wrap">
        <button class="btn btn-primary btn-sm" :disabled="!!busy" @click="act('fetch')">
          {{ busy === 'fetch' ? '抓取中…' : '立即抓取 RSS' }}
        </button>
        <button class="btn btn-sm" :disabled="!!busy" @click="act('sync')">
          {{ busy === 'sync' ? '同步中…' : '同步下载完成' }}
        </button>
        <button class="btn btn-sm" :disabled="!!busy" @click="act('refresh')">重算集数统计</button>
      </div>

      <div class="row-gap mb-14" style="align-items: flex-start; gap: 14px; flex-wrap: wrap">
        <!-- qB 状态 -->
        <div class="card grow" style="min-width: 280px">
          <div class="card-head">
            <div>
              <h3>qBittorrent</h3>
              <div class="sub">下载引擎</div>
            </div>
            <span class="tag" :class="d.qb.online ? 'tag-ok' : 'tag-danger'">
              {{ d.qb.online ? '在线' : '离线' }}
            </span>
          </div>

          <template v-if="d.qb.online">
            <div class="small muted mb-8">版本 {{ d.qb.version }} · 分类内 {{ d.qb.count }} 个任务</div>
            <div class="table-scroll">
              <table class="table table-tight">
                <tbody>
                  <tr>
                    <td class="muted-3">连接</td>
                    <td class="right">{{ d.qb.transfer.connection_status || '-' }}</td>
                  </tr>
                  <tr>
                    <td class="muted-3">下载速率</td>
                    <td class="right">{{ formatSize(d.qb.transfer.dl_info_speed || 0) }}/s</td>
                  </tr>
                  <tr>
                    <td class="muted-3">上传速率</td>
                    <td class="right">{{ formatSize(d.qb.transfer.up_info_speed || 0) }}/s</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <div v-else class="alert alert-danger small">{{ d.qb.error }}</div>
        </div>

        <!-- 磁盘 -->
        <div class="card grow" style="min-width: 320px">
          <div class="card-head">
            <div>
              <h3>存储</h3>
              <div class="sub break">本机磁盘 {{ d.disk.path }}</div>
            </div>
            <span class="tag" :class="d.disk.readable ? 'tag-ok' : 'tag-danger'">
              {{ d.disk.readable ? '可读' : '不可读' }}
            </span>
          </div>

          <!-- 本机磁盘 -->
          <div class="small">
            剩余 <b>{{ formatSize(d.disk.free) }}</b> / 共 {{ formatSize(d.disk.total) }}
          </div>

          <div style="height: 7px; background: var(--surface-2); border-radius: 4px; margin-top: 10px; overflow: hidden; border: 1px solid var(--line)">
            <div
              style="height: 100%; background: var(--brand)"
              :style="{ width: Math.min(100, ((d.disk.total - d.disk.free) / Math.max(1, d.disk.total) * 100)).toFixed(1) + '%' }"
            ></div>
          </div>

          <div class="tiny muted-3 mt-8">
            全盘已用 {{ (((d.disk.total - d.disk.free) / Math.max(1, d.disk.total)) * 100).toFixed(1) }}%
            <template v-if="d.disk.free < 5 * 1073741824">
              · <span style="color: var(--danger)">空间偏紧，建议清理旧番</span>
            </template>
          </div>

          <!-- 占用构成：本机 vs 远程，互不重叠 -->
          <div class="usage-split mt-14">
            <div class="usage-item">
              <div class="usage-head">
                <span class="usage-dot" style="background: var(--brand)"></span>
                <b>本机</b>
                <span class="grow"></span>
                <span>{{ formatSize(d.storage.local.bytes) }}</span>
              </div>
              <div class="usage-bar">
                <div
                  style="background: var(--brand)"
                  :style="{ width: share(d.storage.local.bytes) + '%' }"
                ></div>
              </div>
              <div class="tiny muted-3 mt-6">
                视频 {{ formatSize(d.storage.local.video) }}
                · 字幕 {{ formatSize(d.storage.local.subs) }}
                <template v-if="d.storage.local.other > 0"> · 其他 {{ formatSize(d.storage.local.other) }}</template>
                · 共 {{ d.storage.local.count }} 个文件
              </div>
              <div class="tiny muted-3 break">{{ d.storage.local.roots.download }}<br />{{ d.storage.local.roots.subtitle }}</div>
            </div>

            <div class="usage-item">
              <div class="usage-head">
                <span class="usage-dot" style="background: #7c5cff"></span>
                <b>远程</b>
                <span class="tiny muted-3">S3 / R2</span>
                <span class="grow"></span>
                <span>{{ d.storage.remote.enabled ? formatSize(d.storage.remote.bytes) : '未启用' }}</span>
              </div>
              <div class="usage-bar">
                <div
                  style="background: #7c5cff"
                  :style="{ width: d.storage.remote.enabled ? share(d.storage.remote.bytes) + '%' : '0%' }"
                ></div>
              </div>
              <div class="tiny muted-3 mt-6" v-if="d.storage.remote.enabled">
                {{ d.storage.remote.objects }} 个对象 · 前缀 {{ d.storage.remote.prefix }}
              </div>
              <div class="tiny muted-3 break" v-if="d.storage.remote.public_base">
                {{ d.storage.remote.public_base }}
              </div>
            </div>
          </div>

          <div class="tiny muted-3 mt-10">
            合计 <b>{{ formatSize(d.storage.total) }}</b>
            <span v-if="d.storage.local.cached"> · 本机数字 60 秒内缓存</span>
          </div>

          <div class="alert alert-danger small mt-8" v-if="!d.disk.readable">
            服务进程读不到该目录，播放会失败。检查目录权限是否为 755 且属主可被 www-data 读取。
          </div>
        </div>
      </div>

      <!-- 任务日志 -->
      <div class="card">
        <div class="card-head">
          <h3>最近任务</h3>
          <span class="sub">抓取 / 同步 / 发信</span>
        </div>

        <div v-if="d.jobs?.length" class="table-scroll">
          <table class="table table-tight">
            <thead>
              <tr><th>时间</th><th>任务</th><th>结果</th><th>新增</th><th>耗时</th><th>说明</th></tr>
            </thead>
            <tbody>
              <tr v-for="(j, i) in d.jobs" :key="i">
                <td class="nowrap muted-3">{{ formatTime(j.created_at) }}</td>
                <td class="nowrap">{{ j.job }}</td>
                <td><span class="tag" :class="j.ok ? 'tag-ok' : 'tag-danger'">{{ j.ok ? '成功' : '失败' }}</span></td>
                <td class="nowrap">{{ j.new_count }}</td>
                <td class="nowrap muted-3">{{ j.cost_ms }} ms</td>
                <td class="muted break">{{ j.message }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty">还没有任务记录</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* 本机 / 远程占用对比：两块并排，窄屏自动堆叠 */
.usage-split {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}

.usage-item {
  flex: 1 1 200px;
  min-width: 180px;
}

.usage-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  margin-bottom: 6px;
}

.usage-dot {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  flex: none;
}

/* 轨道 6px、圆角 3px，与上方全盘进度条同族但更细，避免两条视觉打架 */
.usage-bar {
  height: 6px;
  background: var(--surface-2);
  border-radius: 3px;
  overflow: hidden;
  border: 1px solid var(--line);
}

.usage-bar > div {
  height: 100%;
  border-radius: 3px;
  transition: width 0.3s ease;
}
</style>
