<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api, formatTime, formatSize } from '../../api'
import { toast, toastErr } from '../../store/ui'

const MASK = '__SAVED__'

const loading = ref(true)
const saving = ref(false)
const tab = ref('site')
const s = reactive({})

/* ---------- 邮件队列 ---------- */
const mails = ref([])
const mailStat = reactive({ todo: 0, failed: 0 })
const mailLoading = ref(false)
const testTo = ref('')
const testing = ref(false)
const testTrace = ref('')

const TABS = [
  { k: 'site', cn: '站点' },
  { k: 'register', cn: '注册' },
  { k: 'qb', cn: 'qBittorrent' },
  { k: 'download', cn: '下载策略' },
  { k: 'subs', cn: '字幕' },
  { k: 's3', cn: '对象存储' },
  { k: 'smtp', cn: '邮件 SMTP' },
  { k: 'mails', cn: '邮件队列' },
]

/* 布尔型配置项 —— 渲染成开关 */
const BOOLS = [
  'register_enabled',
  'register_invite',
  'register_verify_email',
  'register_need_approve',
  'dedupe_by_ep',
  'auto_fetch',
  's3_enabled',
  's3_auto_upload',
  's3_delete_local',
  's3_require_remux',
]

/* 位图字幕 .sup 的保留策略 */
const SUP_KEEP_CN = {
  primary: '只保留要处理的那一轨（约 17MB/集，磁盘可预期）',
  all: '保留全部语言轨（约 85MB/集，保住所有变体）',
  none: '处理完不留（最省磁盘，但无法重新处理）',
}

/* ---------- 对象存储 ---------- */
const s3Stats = ref(null)
const s3Testing = ref(false)
const s3TestResult = ref(null)
const s3Busy = ref(false)

async function loadS3Stats() {
  try {
    s3Stats.value = await api.get('/api/admin/settings/s3-stats')
  } catch {
    s3Stats.value = null
  }
}

async function testS3() {
  s3Testing.value = true
  s3TestResult.value = null
  try {
    const d = await api.post('/api/admin/settings/s3-test', { write: true })
    s3TestResult.value = { ok: true, msg: d?.msg || '连接正常', detail: d?.detail, base: d?.public_base }
    toast('连接测试通过')
  } catch (e) {
    s3TestResult.value = { ok: false, msg: e.message || '连接失败', detail: e.data?.detail }
    toastErr(e)
  }
  s3Testing.value = false
}

async function pushS3Batch() {
  s3Busy.value = true
  try {
    const d = await api.post('/api/admin/s3/upload-batch', { limit: Number(s.s3_upload_limit) || 2 })
    toast(d?.msg || '已处理')
    await loadS3Stats()
  } catch (e) {
    toastErr(e)
  }
  s3Busy.value = false
}

const isOn = (k) => s[k] === '1' || s[k] === 1 || s[k] === true
function toggle(k) {
  s[k] = isOn(k) ? '0' : '1'
}

async function load() {
  loading.value = true
  try {
    const d = await api.get('/api/admin/settings')
    Object.assign(s, d.settings || {})
  } catch (e) {
    toastErr(e)
  }
  loading.value = false
}

async function save() {
  saving.value = true
  try {
    const payload = {}
    for (const [k, v] of Object.entries(s)) {
      // 密钥原样回传掩码 => 后端跳过，不覆盖已存密码
      payload[k] = v
    }
    const d = await api.put('/api/admin/settings', payload)
    toast(`已保存 ${d?.saved?.length ?? 0} 项`)
    await load()
  } catch (e) {
    toastErr(e)
  }
  saving.value = false
}

/* ---------- SMTP 测试 ---------- */
async function sendTest() {
  testing.value = true
  testTrace.value = ''
  try {
    const d = await api.post('/api/admin/settings/smtp-test', { email: testTo.value.trim() })
    testTrace.value = (d?.trace || []).join('\n') || '发送成功'
    toast('测试邮件已发出')
  } catch (e) {
    testTrace.value = e.message || '发送失败'
    toastErr(e)
  }
  testing.value = false
}

/* ---------- 邮件队列 ---------- */
async function loadMails() {
  mailLoading.value = true
  try {
    const d = await api.get('/api/admin/mails')
    mails.value = d.list || []
    mailStat.todo = d.todo || 0
    mailStat.failed = d.failed || 0
  } catch (e) {
    toastErr(e)
  }
  mailLoading.value = false
}

async function retryMails() {
  try {
    const d = await api.post('/api/admin/mails/retry')
    toast(d?.msg || '已重置')
    await loadMails()
  } catch (e) {
    toastErr(e)
  }
}

function switchTab(k) {
  tab.value = k
  if (k === 'mails') loadMails()
  if (k === 's3') loadS3Stats()
}

const MAIL_TAG = { 0: ['待发送', 'tag-warn'], 1: ['已发送', 'tag-ok'], 2: ['失败', 'tag-danger'] }

/* 起址模式说明 */
const URL_MODE_CN = {
  public: '公开直链（桶需允许公开读）',
  presigned: '预签名直链（桶可保持私有）',
}

onMounted(load)
</script>

<template>
  <div>
    <div class="between mb-14">
      <div>
        <h1>设置</h1>
        <div class="small muted-3 mt-8">站点信息、注册开关、下载器连接、下载策略与发信配置。</div>
      </div>
      <button v-if="tab !== 'mails'" class="btn btn-primary btn-sm" :disabled="saving || loading" @click="save">
        {{ saving ? '保存中…' : '保存设置' }}
      </button>
    </div>

    <div class="seg mb-14">
      <button
        v-for="t in TABS"
        :key="t.k"
        class="seg-item"
        :class="{ on: tab === t.k }"
        @click="switchTab(t.k)"
      >{{ t.cn }}</button>
    </div>

    <div v-if="loading" class="loading"><span class="spin"></span> 加载中</div>

    <template v-else>
      <!-- ============ 站点 ============ -->
      <div v-if="tab === 'site'" class="card">
        <div class="stack">
          <div class="form-grid">
            <div class="field">
              <label class="label">站点名称</label>
              <input v-model="s.site_name" class="input" placeholder="看番" />
              <span class="tiny muted-3">显示在顶栏与页面标题</span>
            </div>
            <div class="field">
              <label class="label">站点地址</label>
              <input v-model="s.site_url" class="input input-mono" placeholder="http://<server-ip>" />
              <span class="tiny muted-3">用于邮件里的验证链接，务必填外部可访问的地址</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ 注册 ============ -->
      <div v-else-if="tab === 'register'" class="card">
        <div class="stack">
          <div class="switch-row">
            <div>
              <div class="bold">开放注册</div>
              <div class="small muted-3">关闭后注册页不可用，只能由管理员开号或邀请码注册</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('register_enabled')" @change="toggle('register_enabled')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">需要邀请码</div>
              <div class="small muted-3">开启后注册必须填写有效邀请码</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('register_invite')" @change="toggle('register_invite')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">验证邮箱</div>
              <div class="small muted-3">开启后注册会先进入「待验证邮箱」状态，点邮件链接才能登录</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('register_verify_email')" @change="toggle('register_verify_email')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">人工审核</div>
              <div class="small muted-3">开启后注册进入「待审核」，由管理员放行</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('register_need_approve')" @change="toggle('register_need_approve')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="field" style="max-width: 280px">
            <label class="label">新用户默认角色</label>
            <select v-model="s.register_default_role" class="select">
              <option value="user">普通用户</option>
              <option value="admin">管理员</option>
            </select>
          </div>
        </div>
      </div>

      <!-- ============ qBittorrent ============ -->
      <div v-else-if="tab === 'qb'" class="card">
        <div class="stack">
          <div class="form-grid">
            <div class="field">
              <label class="label">WebUI 地址</label>
              <input v-model="s.qb_url" class="input input-mono" placeholder="http://127.0.0.1:8080" />
            </div>
            <div class="field">
              <label class="label">用户名</label>
              <input v-model="s.qb_user" class="input" placeholder="admin" />
            </div>
            <div class="field">
              <label class="label">密码</label>
              <input
                v-model="s.qb_pass"
                class="input"
                type="password"
                :placeholder="s.qb_pass === MASK ? '已保存（留空不修改）' : 'WebUI 密码'"
              />
              <span class="tiny muted-3">显示 __SAVED__ 表示已保存，留空即保持不变</span>
            </div>
            <div class="field">
              <label class="label">分类</label>
              <input v-model="s.qb_category" class="input" placeholder="anime" />
            </div>
          </div>
          <div class="field">
            <label class="label">下载保存根目录</label>
            <input v-model="s.download_root" class="input input-mono" placeholder="/data/downloads/anime" />
            <span class="tiny muted-3">同步导入时会从该目录下按种子名找视频文件</span>
          </div>

          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">下载完成后暂停做种</div>
              <div class="small muted-3">
                已完成的任务会自动暂停，不再持续上传，省服务器上行流量。
                常驻进程每 60 秒检查一次（qBittorrent 没有「下载完成」事件回调，只能轮询），
                所以最多会多传一分钟。暂停不影响入库：已完成状态与文件读取都照常。
              </div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('qb_pause_after_done')" @change="toggle('qb_pause_after_done')" />
              <span class="track"></span>
            </label>
          </div>

          <div class="alert alert-brand">
            qBittorrent 的 WebUI 需开启并允许本机访问；密码改动后请点右上角「保存设置」。
            这些设置会被常驻进程每轮重新读取，<b>保存即生效，无需重启</b>。
          </div>
        </div>
      </div>

      <!-- ============ 下载策略 ============ -->
      <div v-else-if="tab === 'download'" class="card">
        <div class="stack">
          <div class="switch-row">
            <div>
              <div class="bold">同番同集去重</div>
              <div class="small muted-3">同一部番的同一集只下一次，避免多字幕组重复下载</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('dedupe_by_ep')" @change="toggle('dedupe_by_ep')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">定时自动抓取</div>
              <div class="small muted-3">常驻进程按间隔自动抓 RSS 并按规则加种</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('auto_fetch')" @change="toggle('auto_fetch')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="field" style="max-width: 320px">
            <label class="label">抓取间隔（分钟）</label>
            <input v-model="s.fetch_interval_min" class="input" type="number" min="1" max="1440" />
            <span class="tiny muted-3">
              每隔多少分钟抓一次 RSS（默认 30）。<b>保存即生效</b>：常驻进程每轮重新读取该值，
              改完下一次循环就会按新间隔走，不用重启（日志里会打印「抓取间隔已动态调整」）
            </span>
          </div>
          <div class="divider"></div>

          <div class="form-grid">
            <div class="field">
              <label class="label">全局最低画质</label>
              <select v-model="s.min_resolution" class="select">
                <option value="2160">2160P</option>
                <option value="1080">1080P</option>
                <option value="720">720P</option>
                <option value="">不限</option>
              </select>
              <span class="tiny muted-3">低于该画质的种子会被丢弃</span>
            </div>
            <div class="field">
              <label class="label">每小时加种上限</label>
              <input v-model="s.hourly_add_limit" class="input" type="number" min="1" max="500" />
              <span class="tiny muted-3">防止规则过宽时瞬间灌爆下载器</span>
            </div>
            <div class="field">
              <label class="label">视频扩展名</label>
              <input v-model="s.video_ext" class="input input-mono" placeholder="mp4,mkv,webm,avi,ts,m2ts" />
              <span class="tiny muted-3">逗号分隔，同步导入时用于识别正片文件</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ 字幕 ============ -->
      <div v-else-if="tab === 'subs'" class="card">
        <div class="stack">
          <div class="field" style="max-width: 560px">
            <label class="label">位图字幕（PGS）怎么处理</label>
            <select v-model="s.subs_bitmap_mode" class="select">
              <option value="image">渲染成图片叠加在画面上（推荐）</option>
              <option value="ocr">OCR 成文本字幕</option>
              <option value="both">两者都做</option>
              <option value="off">只保存 .sup，不转换</option>
            </select>
            <span class="tiny muted-3">
              有些片源（BD / WebRip 的 PGS 字幕）的内嵌字幕是<b>图片</b>而不是文字，
              WebVTT 表达不了。<b>渲染成图片</b>是按它自带的时机、位置、调色板把原画渲成透明
              PNG 叠在视频上 —— 100% 保真、样式特效全在，一轨约 1~2 分钟；
              <b>OCR 成文本</b>可选中可搜索，但一轨十几分钟，且片头曲、特效字幕、画面标牌
              会有错字。全程后台进行，不影响下载 / 上传。
            </span>
          </div>

          <div class="divider"></div>

          <div class="field" style="max-width: 560px">
            <label class="label">位图字幕原文件（.sup）保留策略</label>
            <select v-model="s.subs_sup_keep" class="select">
              <option v-for="(cn, k) in SUP_KEEP_CN" :key="k" :value="k">{{ cn }}</option>
            </select>
            <span class="tiny muted-3">
              位图字幕抽不出文本，而「上传后删除本地」会把源文件删掉 ——
              .sup 是它唯一的原始副本。全部保留可以留住繁体/日文等其它轨，但更占磁盘。
            </span>
          </div>

          <div class="divider"></div>

          <div class="form-grid">
            <div class="field">
              <label class="label">图片格式</label>
              <select v-model="s.subs_img_format" class="select">
                <option value="webp">WebP（约 11MB/集）</option>
                <option value="png">PNG（无损，约 35MB/集）</option>
              </select>
              <span class="tiny muted-3">字幕是带透明通道的纯色块，WebP 有损压缩肉眼无差</span>
            </div>
            <div class="field">
              <label class="label">WebP 质量</label>
              <input v-model="s.subs_img_quality" class="input" type="number" min="50" max="100" />
              <span class="tiny muted-3">90 肉眼无差；调低省磁盘，调高几乎无收益</span>
            </div>
            <div class="field">
              <label class="label">相邻屏合并阈值</label>
              <input v-model="s.subs_img_threshold" class="input" type="text" inputmode="decimal" />
              <span class="tiny muted-3">
                淡入 / 逐字动画会产生大量几乎相同的屏，差异低于该比例就并入上一屏。
                调大更省空间，调小更贴近原动画节奏
              </span>
            </div>
            <div class="field">
              <label class="label">渲染用的 Python</label>
              <input v-model="s.subs_py_bin" class="input input-mono" placeholder="留空用 OCR 那个 venv" />
              <span class="tiny muted-3">需含 pgsrip / cv2 / numpy</span>
            </div>
            <div class="field">
              <label class="label">pgsrip 路径（OCR 程序）</label>
              <input v-model="s.subs_ocr_bin" class="input input-mono" placeholder="/opt/anime-new/venv-ocr/bin/pgsrip" />
              <span class="tiny muted-3">装在独立 venv 里，不污染主程序依赖</span>
            </div>
            <div class="field">
              <label class="label">tesseract 语言包目录</label>
              <input v-model="s.subs_tessdata" class="input input-mono" placeholder="留空自动探测" />
              <span class="tiny muted-3">需要 chi_sim / chi_tra（简体、繁体）</span>
            </div>
            <div class="field">
              <label class="label">ffmpeg 路径</label>
              <input v-model="s.ffmpeg_path" class="input input-mono" placeholder="留空用 PATH 里的 ffmpeg" />
              <span class="tiny muted-3">remux 与字幕抽取都靠它</span>
            </div>
          </div>

          <div class="alert alert-warn">
            两种产物的取舍：<b>图片叠加</b>保真但不可选中、不可搜索；
            <b>OCR 文本</b>可选中可搜索，但正文对白质量良好、片头曲与特效字幕会有错字——
            那些本身就是图形排版，OCR 无法还原。两者都开时播放页的字幕菜单里会同时出现。
          </div>

          <div class="alert alert-brand">
            环境自检：<code>php bin/ocr_subs.php env</code>（两条路一起查）；
            查看进度：<code>php bin/ocr_subs.php status</code>；
            手工重渲某集：<code>php bin/ocr_subs.php render &lt;episodeId&gt;</code>
          </div>
        </div>
      </div>

      <!-- ============ 对象存储 ============ -->
      <div v-else-if="tab === 's3'" class="card">
        <div class="stack">
          <div class="switch-row">
            <div>
              <div class="bold">启用对象存储</div>
              <div class="small muted-3">开启后，下载完成（并 remux 好）的剧集会按下方策略上传到 S3 / R2</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('s3_enabled')" @change="toggle('s3_enabled')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="form-grid">
            <div class="field">
              <label class="label">Endpoint</label>
              <input v-model="s.s3_endpoint" class="input input-mono" placeholder="https://<account>.r2.cloudflarestorage.com" />
              <span class="tiny muted-3">
                只填到主机名即可。<b>桶名请填下面的 Bucket 字段</b>；若末尾多带了
                <code>/桶名</code>，系统会自动忽略并提示（不影响使用）
              </span>
            </div>
            <div class="field">
              <label class="label">Region</label>
              <input v-model="s.s3_region" class="input input-mono" placeholder="auto" />
              <span class="tiny muted-3">R2 固定填 auto</span>
            </div>
            <div class="field">
              <label class="label">Bucket</label>
              <input v-model="s.s3_bucket" class="input input-mono" placeholder="anime" />
            </div>
            <div class="field">
              <label class="label">对象键前缀</label>
              <input v-model="s.s3_prefix" class="input input-mono" placeholder="anime" />
              <span class="tiny muted-3">文件会存到 {前缀}/{番剧ID}/{集数}-{文件名}</span>
            </div>
            <div class="field">
              <label class="label">Access Key ID</label>
              <input v-model="s.s3_access_key" class="input input-mono" placeholder="R2 API 令牌的 Access Key ID" />
            </div>
            <div class="field">
              <label class="label">Secret Access Key</label>
              <input
                v-model="s.s3_secret_key"
                class="input input-mono"
                type="password"
                :placeholder="s.s3_secret_key === MASK ? '已保存（留空不修改）' : 'R2 API 令牌的 Secret'"
              />
              <span class="tiny muted-3">显示 __SAVED__ 表示已保存，留空即保持不变</span>
            </div>
          </div>

          <div class="divider"></div>

          <div class="form-grid">
            <div class="field">
              <label class="label">公开访问基址</label>
              <input v-model="s.s3_public_base" class="input input-mono" placeholder="https://pub-xxxx.r2.dev 或 https://cdn.example.com" />
              <span class="tiny muted-3">用于生成播放直链；R2 需在桶设置里开启 r2.dev 或绑定自定义域</span>
            </div>
            <div class="field">
              <label class="label">取址模式</label>
              <select v-model="s.s3_url_mode" class="select">
                <option v-for="(cn, k) in URL_MODE_CN" :key="k" :value="k">{{ cn }}</option>
              </select>
              <span class="tiny muted-3">下载（dl=1）场景始终走预签名，以便带上 attachment 语义</span>
            </div>
            <div class="field">
              <label class="label">每次上传并发</label>
              <input v-model="s.s3_upload_limit" class="input" type="number" min="1" max="20" />
              <span class="tiny muted-3">常驻进程每轮最多上传几集，默认 2</span>
            </div>
          </div>

          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">自动上传</div>
              <div class="small muted-3">从 qBittorrent 同步入库后自动排队上传，无需人工点</div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('s3_auto_upload')" @change="toggle('s3_auto_upload')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">上传后删除本地文件</div>
              <div class="small muted-3">
                远端大小校验通过后才删除，之后播放由对象存储直链提供。
                <b>关闭则本地留副本，不省磁盘。</b>
              </div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('s3_delete_local')" @change="toggle('s3_delete_local')" />
              <span class="track"></span>
            </label>
          </div>
          <div class="divider"></div>

          <div class="switch-row">
            <div>
              <div class="bold">等 remux 完成再上传</div>
              <div class="small muted-3">
                开着传的是浏览器原生可播的 MP4；关闭会把原始 MKV 也传上去（HEVC 之外在 Chrome 里不能直接播）
              </div>
            </div>
            <label class="switch">
              <input type="checkbox" :checked="isOn('s3_require_remux')" @change="toggle('s3_require_remux')" />
              <span class="track"></span>
            </label>
          </div>

          <div class="divider"></div>

          <div class="field">
            <label class="label">连接测试</label>
            <div class="row-gap">
              <button class="btn" :disabled="s3Testing" @click="testS3">
                {{ s3Testing ? '测试中…' : '测试读 + 写' }}
              </button>
              <button class="btn btn-ghost" :disabled="s3Busy" @click="pushS3Batch">
                {{ s3Busy ? '处理中…' : '立即上传待传队列' }}
              </button>
            </div>
            <span class="tiny muted-3">先点右上角「保存设置」再测试，否则用的是旧配置</span>
          </div>

          <div v-if="s3TestResult" class="alert" :class="s3TestResult.ok ? 'alert-brand' : 'alert-warn'">
            <div class="bold">{{ s3TestResult.ok ? '连接成功' : '连接失败' }}</div>
            <div class="small">{{ s3TestResult.msg }}</div>
            <div v-if="s3TestResult.base" class="tiny muted-3 mt-8">公开基址：{{ s3TestResult.base }}</div>
            <div v-if="s3TestResult.detail" class="code-block mt-8">{{ JSON.stringify(s3TestResult.detail, null, 2) }}</div>
          </div>

          <template v-if="s3Stats">
            <div class="divider"></div>
            <div class="small muted mb-8">用量概况</div>
            <div class="row-gap" style="flex-wrap: wrap">
              <span class="tag tag-ok">已上传 {{ s3Stats.uploaded }}</span>
              <span class="tag tag-mute">已省本地 {{ s3Stats.purged }}</span>
              <span class="tag tag-brand">仅远程 {{ s3Stats.remote_only }}</span>
              <span class="tag tag-warn">待上传 {{ s3Stats.queued }}</span>
              <span v-if="s3Stats.failed" class="tag tag-danger">失败 {{ s3Stats.failed }}</span>
              <span class="tag tag-mute">远端占用 {{ formatSize(s3Stats.remote_bytes) }}</span>
              <span v-if="s3Stats.disk_free !== null" class="tag tag-mute">本地剩余 {{ formatSize(s3Stats.disk_free) }}</span>
            </div>
            <div v-if="s3Stats.config_hint" class="tiny mt-8" style="color: var(--warn)">{{ s3Stats.config_hint }}</div>
          </template>

          <div class="alert alert-warn">
            Cloudflare R2 需要三步：① 建桶；② 「Manage R2 API Tokens」建一个
            <b>Object Read &amp; Write</b> 令牌，把 Access Key ID / Secret 填到上面；
            ③ 桶设置里开启 r2.dev 子域或绑定自定义域，填到「公开访问基址」。
            公开直链模式下任何拿到链接的人都能访问视频，介意的话把取址模式改成预签名。
          </div>
        </div>
      </div>

      <!-- ============ SMTP ============ -->
      <div v-else-if="tab === 'smtp'" class="card">
        <div class="stack">
          <div class="form-grid">
            <div class="field">
              <label class="label">SMTP 主机</label>
              <input v-model="s.smtp_host" class="input input-mono" placeholder="smtp.qq.com" />
            </div>
            <div class="field">
              <label class="label">端口</label>
              <input v-model="s.smtp_port" class="input" type="number" placeholder="465" />
            </div>
            <div class="field">
              <label class="label">加密方式</label>
              <select v-model="s.smtp_secure" class="select">
                <option value="ssl">SSL（465）</option>
                <option value="tls">STARTTLS（587）</option>
                <option value="none">不加密（25）</option>
              </select>
            </div>
            <div class="field">
              <label class="label">账号</label>
              <input v-model="s.smtp_user" class="input" placeholder="noreply@example.com" />
            </div>
            <div class="field">
              <label class="label">密码 / 授权码</label>
              <input
                v-model="s.smtp_pass"
                class="input"
                type="password"
                :placeholder="s.smtp_pass === MASK ? '已保存（留空不修改）' : '授权码'"
              />
            </div>
            <div class="field">
              <label class="label">发件人地址</label>
              <input v-model="s.smtp_from" class="input input-mono" placeholder="noreply@example.com" />
            </div>
            <div class="field">
              <label class="label">发件人名称</label>
              <input v-model="s.smtp_from_name" class="input" placeholder="看番" />
            </div>
          </div>

          <div class="divider"></div>

          <div class="field">
            <label class="label">发送测试邮件</label>
            <div class="row-gap">
              <input v-model="testTo" class="input grow" placeholder="收件邮箱，留空则发给自己" />
              <button class="btn" :disabled="testing" @click="sendTest">
                {{ testing ? '发送中…' : '发送测试' }}
              </button>
            </div>
            <span class="tiny muted-3">先点右上角「保存设置」再测试，否则用的是旧配置</span>
          </div>

          <div v-if="testTrace" class="code-block">{{ testTrace }}</div>

          <div class="alert alert-warn">
            腾讯/QQ 邮箱需在后台开启 SMTP 并获取「授权码」当作密码；QQ 个人邮箱发件人地址须与账号一致。
          </div>
        </div>
      </div>

      <!-- ============ 邮件队列 ============ -->
      <div v-else-if="tab === 'mails'" class="card card-tight">
        <div class="between mb-14">
          <div class="row-gap">
            <span class="tag tag-warn">待发送 {{ mailStat.todo }}</span>
            <span class="tag tag-danger">失败 {{ mailStat.failed }}</span>
          </div>
          <div class="row-gap">
            <button class="btn btn-sm btn-ghost" :disabled="mailLoading" @click="loadMails">刷新</button>
            <button class="btn btn-sm" :disabled="!mailStat.failed" @click="retryMails">重投失败</button>
          </div>
        </div>

        <div v-if="mailLoading" class="loading"><span class="spin"></span> 加载中</div>
        <div v-else-if="mails.length" class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th style="width: 52px">ID</th>
                <th>收件人</th>
                <th>主题</th>
                <th style="width: 84px">状态</th>
                <th style="width: 58px">重试</th>
                <th style="width: 132px">创建</th>
                <th style="width: 132px">发送</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in mails" :key="m.id">
                <td class="muted-3">{{ m.id }}</td>
                <td class="nowrap">{{ m.to_email }}</td>
                <td class="break" style="max-width: 320px">
                  {{ m.subject }}
                  <div v-if="m.error" class="tiny" style="color: var(--danger)">{{ m.error }}</div>
                </td>
                <td>
                  <span class="tag" :class="(MAIL_TAG[m.status] || MAIL_TAG[0])[1]">
                    {{ (MAIL_TAG[m.status] || MAIL_TAG[0])[0] }}
                  </span>
                </td>
                <td>{{ m.retry || 0 }}</td>
                <td class="nowrap muted-3 small">{{ formatTime(m.created_at) }}</td>
                <td class="nowrap muted-3 small">{{ m.sent_at ? formatTime(m.sent_at) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty">
          <div class="big">邮件队列为空</div>
          <div>注册验证、找回密码、审核通过的通知都会排队在这里发送</div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.switch-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}
</style>
