<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { createChatPoller } from '../lib/chatPolling'
import { StudioSession, type BackgroundState, type StudioSlide } from '../lib/studioSession'
import { SpeakerLayout, StageSource } from '../lib/stageCompositor'
import StudioSlidesPanel from '../Components/StudioSlidesPanel.vue'
import StudioChatPanel, { ChatMessage } from '../Components/StudioChatPanel.vue'
import GreenroomLogo from '../Components/GreenroomLogo.vue'

interface StudioRoom {
  slug: string
  title: string
  description?: string
  scheduled_at?: string | null
  registrations_count?: number
  is_live: boolean
  stage_source: StageSource
  speaker_layout: SpeakerLayout
  current_slide_page: number
  has_owner_account: boolean
}

const props = defineProps<{
  room: StudioRoom
  presenterKey: string
  currentSlideId: number | null
  slides: StudioSlide[]
  maxSlideKb: number
  registrationUrl?: string
  emailsUrl?: string
}>()

const page = usePage()
const authUser = computed(() => (page.props.auth as { user: { name: string } | null } | undefined)?.user ?? null)

const stageCanvas = ref<HTMLCanvasElement | null>(null)
const cameraVideo = ref<HTMLVideoElement | null>(null)
const screenVideo = ref<HTMLVideoElement | null>(null)

const tab = ref<'slides' | 'backstage' | 'guest'>('slides')
const presenterName = ref(authUser.value?.name || 'Presenter')
const backstageMessages = ref<ChatMessage[]>([])
const guestMessages = ref<ChatMessage[]>([])
const copied = ref('')
const resettingStudioLink = ref(false)

const isLive = ref(props.room.is_live)
const liveStatus = ref(props.room.is_live ? 'Live' : 'Off air')
const viewerCount = ref(0)
const stageSource = ref<StageSource>(props.room.stage_source || 'slide')
const speakerLayout = ref<SpeakerLayout>(props.room.speaker_layout || 'sidebar')
const camOn = ref(false)
const screenOn = ref(false)
const micOn = ref(true)
const mediaError = ref('')
const backgroundState = ref<BackgroundState>({ mode: 'none', imageId: null, available: true })
const backgroundInput = ref<HTMLInputElement | null>(null)
const backgroundError = ref('')
const backgrounds = [
  { label: 'Dunes abstract', url: '/backgrounds/dunes-abstract.png' },
  { label: 'Bookcase lounge', url: '/backgrounds/bookcase-lounge.png' },
  { label: 'Modern office', url: '/backgrounds/modern-office.png' },
  { label: 'Library wall', url: '/backgrounds/library-wall.png' },
]
const backgroundModes = [
  { label: 'None', value: 'none' },
  { label: 'Blur', value: 'blur' },
] as const
const slidePage = ref(props.room.current_slide_page || 1)
const slideTotal = ref(0)
const slideError = ref('')

const currentSlide = computed(() => props.slides.find((s) => s.id === props.currentSlideId) ?? null)

const session = new StudioSession(props.room.slug, props.presenterKey, {
  onBackgroundState: (state) => { backgroundState.value = state },
  onViewerCount: (n) => { viewerCount.value = n },
  onSlideMeta: (p, t, err) => { slidePage.value = p; slideTotal.value = t; slideError.value = err },
  onMediaState: (c, s, m, err) => { camOn.value = c; screenOn.value = s; micOn.value = m; mediaError.value = err },
  onLiveState: (live, status) => { isLive.value = live; liveStatus.value = status },
})

session.stageSource = stageSource.value
session.speakerLayout = speakerLayout.value
session.roomTitle = props.room.title
session.speakerName = presenterName.value
session.isLive = isLive.value

let backgroundSelection = 0

async function selectBackground(url: string): Promise<void> {
  const selection = ++backgroundSelection
  backgroundError.value = ''
  try {
    await session.setBackgroundImage(url)
  } catch {
    if (selection === backgroundSelection) backgroundError.value = 'Could not open this background image.'
  }
}

async function uploadBackground(event: Event): Promise<void> {
  const input = event.currentTarget as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  const url = URL.createObjectURL(file)
  try {
    await selectBackground(url)
  } finally {
    URL.revokeObjectURL(url)
  }
}

function selectBackgroundMode(mode: 'none' | 'blur'): void {
  backgroundSelection++
  backgroundError.value = ''
  session.setBackgroundMode(mode)
}

let pollTimer: number | null = null

const pollBackstage = createChatPoller<{ messages: ChatMessage[] }>(
  () => `/r/${props.room.slug}/chat/backstage?key=${encodeURIComponent(props.presenterKey)}`,
  (data) => { backstageMessages.value = data.messages || [] },
)
const pollGuestChat = createChatPoller<{ messages: ChatMessage[] }>(
  () => `/r/${props.room.slug}/chat/room?key=${encodeURIComponent(props.presenterKey)}`,
  (data) => { guestMessages.value = data.messages || [] },
)

async function fetchMessages(): Promise<void> {
  await Promise.all([pollBackstage(), pollGuestChat()])
}

async function copyLink(kind: 'guest' | 'studio' | 'register'): Promise<void> {
  const url = kind === 'register'
    ? (props.registrationUrl || `${window.location.origin}/r/${props.room.slug}/register`)
    : kind === 'guest'
      ? `${window.location.origin}/r/${props.room.slug}`
      : `${window.location.origin}/studio/${props.room.slug}/${props.presenterKey}`
  await navigator.clipboard?.writeText(url).catch(() => {})
  copied.value = kind
  window.setTimeout(() => { if (copied.value === kind) copied.value = '' }, 1800)
}

function resetStudioLink(): void {
  if (!window.confirm('Reset the studio link? The current studio link will stop working and anyone holding it will lose presenter access. A new link will be created.')) return

  resettingStudioLink.value = true
  router.post(`/studio/${props.room.slug}/${props.presenterKey}/rotate`, {}, {
    preserveState: false,
    replace: true,
    onFinish: () => { resettingStudioLink.value = false },
  })
}

function setSource(src: StageSource): void {
  stageSource.value = src
  void session.setLayout(src, speakerLayout.value)
}

function setSpeakerLayout(layout: SpeakerLayout): void {
  speakerLayout.value = layout
  void session.setLayout(stageSource.value, layout)
}

watch(presenterName, (val) => { session.speakerName = val || 'Presenter' })
watch(currentSlide, (slide) => { void session.loadSlide(slide, 1) })

onMounted(() => {
  if (stageCanvas.value && cameraVideo.value && screenVideo.value) {
    session.attach(stageCanvas.value, cameraVideo.value, screenVideo.value)
  }
  void session.loadSlide(currentSlide.value, props.room.current_slide_page || 1)
  void fetchMessages()
  pollTimer = window.setInterval(() => { void fetchMessages() }, 2500)
})

onBeforeUnmount(() => {
  if (pollTimer !== null) window.clearInterval(pollTimer)
  session.destroy()
})
</script>

<template>
  <Head :title="`Studio - ${room.title}`" />
  <div class="h-screen flex flex-col bg-[#202423] text-[#FAF8F2] overflow-hidden">
    <header class="shrink-0 h-15 px-5 border-b border-white/10 bg-[#1A1E1D] flex items-center justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <Link href="/" class="shrink-0"><GreenroomLogo size="sm" tone="light" /></Link>
        <span class="text-white/25">/</span>
        <h1 class="font-semibold truncate text-sm text-[#FAF8F2]">{{ room.title }}</h1>
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-0.5 text-[11px] font-bold tracking-wide"
          :class="isLive ? 'bg-[#C43D3D] text-white' : 'bg-[#DCD3F7]/20 text-[#DCD3F7] border border-[#DCD3F7]/30'"
        >
          <span v-if="isLive" class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />
          {{ isLive ? `LIVE · ${viewerCount}` : liveStatus }}
        </span>
      </div>
      <div class="flex items-center gap-2 text-xs flex-wrap justify-end">
        <Link
          :href="emailsUrl || `/studio/${room.slug}/${presenterKey}/emails`"
          class="rounded-full bg-[#C9F284] text-[#202423] px-3 py-1.5 font-semibold hover:bg-[#baf06d] transition"
        >
          Schedule &amp; Emails{{ room.registrations_count ? ` (${room.registrations_count})` : '' }}
        </Link>
        <button type="button" @click="copyLink('register')" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 transition">
          {{ copied === 'register' ? 'Copied register' : 'Register link' }}
        </button>
        <button type="button" @click="copyLink('guest')" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 transition">
          {{ copied === 'guest' ? 'Copied guest' : 'Guest link' }}
        </button>
        <button type="button" @click="copyLink('studio')" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 transition">
          {{ copied === 'studio' ? 'Copied studio' : 'Studio link' }}
        </button>
        <button type="button" :disabled="resettingStudioLink" @click="resetStudioLink" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 disabled:opacity-50 transition">
          Reset studio link
        </button>
        <Link v-if="authUser" href="/events" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 transition">
          My events
        </Link>
        <Link v-else href="/login" class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-[#FAF8F2]/90 hover:bg-white/10 transition">
          Sign in
        </Link>
        <button
          v-if="!isLive"
          type="button"
          @click="session.goLive()"
          class="rounded-full bg-[#006849] hover:bg-[#00553b] px-4 py-1.5 font-semibold text-white transition"
        >
          Go live
        </button>
        <button
          v-else
          type="button"
          @click="session.endLive()"
          class="rounded-full bg-[#C43D3D] hover:bg-[#a83232] px-4 py-1.5 font-semibold text-white transition"
        >
          End broadcast
        </button>
      </div>
    </header>

    <div class="flex-1 flex min-h-0">
      <main class="flex-1 flex flex-col min-w-0 p-5 gap-4 overflow-y-auto bg-[#202423]">
        <div class="relative w-full aspect-video max-h-[68vh] mx-auto rounded-2xl overflow-hidden border border-white/10 bg-[#151817] shadow-2xl">
          <canvas ref="stageCanvas" width="1280" height="720" class="w-full h-full object-contain block" />
          <video ref="cameraVideo" playsinline autoplay muted class="hidden" />
          <video ref="screenVideo" playsinline autoplay muted class="hidden" />
        </div>

        <p v-if="mediaError || slideError" class="text-xs text-[#ffb4b4] text-center">
          {{ mediaError || slideError }}
        </p>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/10 bg-[#2A2F2E] px-4 py-3 text-xs">
          <div class="flex items-center gap-2">
            <button
              type="button"
              @click="session.toggleCamera()"
              class="rounded-full px-3.5 py-1.5 font-semibold transition"
              :class="camOn ? 'bg-[#006849] text-white' : 'bg-white/10 text-[#FAF8F2] hover:bg-white/15'"
            >
              {{ camOn ? 'Camera on' : 'Share camera' }}
            </button>
            <button
              v-if="camOn"
              type="button"
              @click="session.toggleMic()"
              class="rounded-full px-3.5 py-1.5 font-semibold transition"
              :class="micOn ? 'bg-white/10 text-[#FAF8F2]' : 'bg-[#C43D3D] text-white'"
            >
              {{ micOn ? 'Mic on' : 'Mic muted' }}
            </button>
            <button
              type="button"
              @click="session.toggleScreen()"
              class="rounded-full px-3.5 py-1.5 font-semibold transition"
              :class="screenOn ? 'bg-[#006849] text-white' : 'bg-white/10 text-[#FAF8F2] hover:bg-white/15'"
            >
              {{ screenOn ? 'Stop screen' : 'Share screen' }}
            </button>
          </div>

          <div class="flex items-center gap-1.5 flex-wrap">
            <span class="text-[#FAF8F2]/60 mr-1">Main:</span>
            <button
              type="button"
              @click="setSource('slide')"
              class="rounded-full px-3 py-1 font-medium transition"
              :class="stageSource === 'slide' ? 'bg-[#C9F284] text-[#202423] font-semibold' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
            >
              Slide
            </button>
            <button
              type="button"
              @click="setSource('screen')"
              class="rounded-full px-3 py-1 font-medium transition"
              :class="stageSource === 'screen' ? 'bg-[#C9F284] text-[#202423] font-semibold' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
            >
              Screen
            </button>
            <span class="text-[#FAF8F2]/60 ml-2 mr-1">Speaker:</span>
            <button
              type="button"
              @click="setSpeakerLayout('sidebar')"
              class="rounded-full px-3 py-1 font-medium transition"
              :class="speakerLayout === 'sidebar' ? 'bg-[#C9F284] text-[#202423] font-semibold' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
            >
              Sidebar
            </button>
            <button
              type="button"
              @click="setSpeakerLayout('overlay')"
              class="rounded-full px-3 py-1 font-medium transition"
              :class="speakerLayout === 'overlay' ? 'bg-[#C9F284] text-[#202423] font-semibold' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
            >
              Overlay
            </button>
            <button
              type="button"
              @click="setSpeakerLayout('hidden')"
              class="rounded-full px-3 py-1 font-medium transition"
              :class="speakerLayout === 'hidden' ? 'bg-[#C9F284] text-[#202423] font-semibold' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
            >
              Hide
            </button>
          </div>

          <div v-if="slideTotal > 0" class="flex items-center gap-2">
            <button
              type="button"
              :disabled="slidePage <= 1"
              @click="session.setPage(slidePage - 1)"
              class="rounded-full bg-white/10 hover:bg-white/15 disabled:opacity-40 px-3 py-1 font-medium"
            >
              Prev
            </button>
            <span class="text-[#FAF8F2]/80 font-medium">{{ slidePage }} / {{ slideTotal }}</span>
            <button
              type="button"
              :disabled="slidePage >= slideTotal"
              @click="session.setPage(slidePage + 1)"
              class="rounded-full bg-white/10 hover:bg-white/15 disabled:opacity-40 px-3 py-1 font-medium"
            >
              Next
            </button>
          </div>

          <div role="group" aria-label="Background" class="flex w-full flex-wrap items-center gap-2 border-t border-white/10 pt-3">
            <span class="mr-1 text-[#FAF8F2]/60">Background</span>
            <button
              v-for="option in backgroundModes"
              :key="option.value"
              type="button"
              :aria-pressed="backgroundState.mode === option.value"
              :disabled="option.value === 'blur' && !backgroundState.available"
              class="rounded-full px-3 py-1 font-medium transition disabled:opacity-40"
              :class="backgroundState.mode === option.value ? 'bg-[#C9F284] text-[#202423]' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
              @click="selectBackgroundMode(option.value)"
            >
              {{ option.label }}
            </button>
            <button
              v-for="background in backgrounds"
              :key="background.url"
              type="button"
              :aria-label="background.label"
              :title="background.label"
              :aria-pressed="backgroundState.mode === 'image' && backgroundState.imageId === background.url"
              :disabled="!backgroundState.available"
              class="h-9 w-14 overflow-hidden rounded-md border-2 transition disabled:opacity-40"
              :class="backgroundState.mode === 'image' && backgroundState.imageId === background.url ? 'border-[#C9F284]' : 'border-white/10 hover:border-white/50'"
              @click="selectBackground(background.url)"
            >
              <img :src="background.url" alt="" class="h-full w-full object-cover" loading="lazy" />
            </button>
            <button
              type="button"
              :disabled="!backgroundState.available"
              class="rounded-full px-3 py-1 font-medium transition disabled:opacity-40"
              :class="backgroundState.mode === 'image' && backgroundState.imageId === 'upload' ? 'bg-[#C9F284] text-[#202423]' : 'bg-white/10 text-[#FAF8F2]/80 hover:bg-white/15'"
              @click="backgroundInput?.click()"
            >
              {{ backgroundState.mode === 'image' && backgroundState.imageId === 'upload' ? 'Uploaded · Replace' : 'Upload' }}
            </button>
            <input ref="backgroundInput" type="file" accept="image/*" class="hidden" @change="uploadBackground" />
            <p v-if="!backgroundState.available || backgroundError" role="status" class="w-full text-[#ffb4b4]">
              {{ !backgroundState.available ? 'Background effects unavailable' : backgroundError }}
            </p>
          </div>
        </div>
      </main>

      <aside class="w-84 shrink-0 border-l border-[#202423]/12 flex flex-col min-h-0 bg-[#FAF8F2] text-[#202423]">
        <div class="grid grid-cols-3 border-b border-[#202423]/10 text-xs font-semibold bg-white/60">
          <button
            type="button"
            @click="tab = 'slides'"
            class="py-3 border-b-2 transition"
            :class="tab === 'slides' ? 'border-[#006849] text-[#006849]' : 'border-transparent text-[#202423]/55 hover:text-[#202423]'"
          >
            Slides ({{ slides.length }})
          </button>
          <button
            type="button"
            @click="tab = 'backstage'"
            class="py-3 border-b-2 transition"
            :class="tab === 'backstage' ? 'border-[#006849] text-[#006849]' : 'border-transparent text-[#202423]/55 hover:text-[#202423]'"
          >
            Backstage
          </button>
          <button
            type="button"
            @click="tab = 'guest'"
            class="py-3 border-b-2 transition"
            :class="tab === 'guest' ? 'border-[#006849] text-[#006849]' : 'border-transparent text-[#202423]/55 hover:text-[#202423]'"
          >
            Guest ({{ guestMessages.length }})
          </button>
        </div>

        <StudioSlidesPanel
          v-if="tab === 'slides'"
          :slug="room.slug"
          :presenter-key="presenterKey"
          :slides="slides"
          :current-slide-id="currentSlideId"
          :max-slide-kb="maxSlideKb"
        />
        <StudioChatPanel
          v-else-if="tab === 'backstage'"
          :slug="room.slug"
          :presenter-key="presenterKey"
          channel="backstage"
          :messages="backstageMessages"
          v-model:presenter-name="presenterName"
          @refresh="fetchMessages"
        />
        <StudioChatPanel
          v-else
          :slug="room.slug"
          :presenter-key="presenterKey"
          channel="room"
          :messages="guestMessages"
          v-model:presenter-name="presenterName"
          @refresh="fetchMessages"
        />
      </aside>
    </div>
  </div>
</template>
