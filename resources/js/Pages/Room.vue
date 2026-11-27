<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import GreenroomLogo from '../Components/GreenroomLogo.vue'
import { createChatPoller } from '../lib/chatPolling'
import { loadPdfDeck, loadPptxDeck, type RenderedDeck } from '../lib/slideRenderer'
import { csrfToken, GuestReceiver } from '../lib/webrtcSignaling'

interface SlideMeta { id: number; name: string; mime: string }
interface ChatMsg { id: number; author_name: string; body: string; is_presenter: boolean; hidden: boolean; pinned: boolean; created_at: string | null }
interface RoomProps {
  slug: string; title: string; is_live: boolean
  live_started_at: string | null; live_ended_at: string | null
  stage_source: 'slide' | 'screen'; speaker_layout: 'sidebar' | 'overlay' | 'hidden'
  current_slide_page: number
}

const props = defineProps<{ room: RoomProps; guestName: string; slide: SlideMeta | null }>()

const nameInput = ref(props.guestName)
const joinedName = ref(props.guestName)
const isLive = ref(props.room.is_live)
const liveSlide = ref<SlideMeta | null>(props.slide)
const slidePage = ref(props.room.current_slide_page || 1)
const messages = ref<ChatMsg[]>([])
const chatBody = ref('')
const chatError = ref('')

// WebRTC live video stream
const liveVideoEl = ref<HTMLVideoElement | null>(null)
const hasRemoteStream = ref(false)
const hasFrames = ref(false)
function onVideoFrames() {
  hasFrames.value = (liveVideoEl.value?.videoWidth ?? 0) > 0
}
const streamState = ref('idle')
const muted = ref(true)
let receiver: GuestReceiver | null = null

// Fallback canvas slide renderer (when not live or before WebRTC stream arrives)
const slideCanvasEl = ref<HTMLCanvasElement | null>(null)
const slideError = ref('')
const slideTotalPages = ref(0)
let loadedSlideId: number | null = null
let deck: RenderedDeck | null = null

const pinned = computed(() => messages.value.filter((m) => m.pinned))

function joinRoom() {
  if (!nameInput.value.trim()) return
  router.post(`/r/${props.room.slug}/join`, { name: nameInput.value.trim() }, {
    preserveScroll: true,
    onSuccess: () => { joinedName.value = nameInput.value.trim() },
  })
}

const fetchState = createChatPoller<{
  messages: ChatMsg[]
  live: { is_live: boolean; current_slide_page: number; slide: SlideMeta | null }
}>(() => `/r/${props.room.slug}/chat/room`, (data) => {
  messages.value = data.messages ?? []
  if (data.live) {
    isLive.value = !!data.live.is_live
    slidePage.value = data.live.current_slide_page || 1
    liveSlide.value = data.live.slide
      ? { id: data.live.slide.id, name: data.live.slide.name, mime: data.live.slide.mime }
      : null
  }
})

async function renderFallbackSlide() {
  const slide = liveSlide.value
  if (!slide) {
    loadedSlideId = null
    deck = null
    slideTotalPages.value = 0
    return
  }
  try {
    slideError.value = ''
    if (loadedSlideId !== slide.id || !deck) {
      loadedSlideId = slide.id
      const isPdf = slide.mime === 'application/pdf' || slide.name.toLowerCase().endsWith('.pdf')
      deck = isPdf
        ? await loadPdfDeck(`/r/${props.room.slug}/slides/${slide.id}`)
        : await loadPptxDeck(`/r/${props.room.slug}/slides/${slide.id}/pages`, slide.name)
      slideTotalPages.value = deck.totalPages
    }
    const pageCanvas = await deck.getPageCanvas(slidePage.value)
    const target = slideCanvasEl.value
    if (pageCanvas && target) {
      target.width = pageCanvas.width
      target.height = pageCanvas.height
      target.getContext('2d')?.drawImage(pageCanvas, 0, 0)
    }
  } catch (err: any) {
    slideError.value = err?.message || 'Unable to render slide deck.'
  }
}

function syncWebRtc() {
  if (isLive.value) {
    if (!receiver) {
      receiver = new GuestReceiver(
        props.room.slug,
        (stream) => {
          hasRemoteStream.value = !!stream
          if (!stream) hasFrames.value = false
          if (liveVideoEl.value) {
            liveVideoEl.value.srcObject = stream
            if (stream) void liveVideoEl.value.play().catch(() => {})
          }
        },
        (st) => { streamState.value = st },
      )
      receiver.start()
    }
  } else if (receiver) {
    receiver.stop()
    receiver = null
    hasRemoteStream.value = false
    hasFrames.value = false
  }
}

function toggleMute() {
  muted.value = !muted.value
  if (liveVideoEl.value) liveVideoEl.value.muted = muted.value
}

async function sendMessage() {
  const body = chatBody.value.trim()
  if (!body) return
  chatError.value = ''
  const res = await fetch(`/r/${props.room.slug}/chat/room`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    body: JSON.stringify({ body }),
  })
  if (!res.ok) {
    chatError.value = 'Could not send message.'
    return
  }
  chatBody.value = ''
  await fetchState()
}

watch([liveSlide, slidePage], () => { void renderFallbackSlide() }, { immediate: true })
watch(isLive, () => syncWebRtc(), { immediate: true })

let pollTimer: number | null = null
onMounted(() => {
  void fetchState()
  pollTimer = window.setInterval(() => void fetchState(), 2500)
})
onBeforeUnmount(() => {
  if (pollTimer !== null) window.clearInterval(pollTimer)
  receiver?.stop()
})
</script>

<template>
  <Head :title="`${room.title} - Greenroom`" />
  <div class="min-h-screen bg-[#FAF8F2] text-[#202423] flex flex-col">
    <header class="border-b border-[#E6E2D8] bg-[#FAF8F2]/95 px-6 py-3.5 flex items-center justify-between gap-4">
      <div class="flex items-center gap-3 min-w-0">
        <Link href="/" class="shrink-0">
          <GreenroomLogo size="sm" />
        </Link>
        <span class="text-[#C7C2B6]">/</span>
        <h1 class="font-semibold text-[#202423] truncate">{{ room.title }}</h1>
        <span v-if="isLive" class="inline-flex items-center gap-1.5 rounded-full bg-[#C43D3D] px-2.5 py-0.5 text-xs font-semibold text-white">
          <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" /> LIVE
        </span>
        <span v-else class="rounded-full bg-[#DCD3F7] px-2.5 py-0.5 text-xs font-semibold text-[#202423]">Waiting room</span>
      </div>
      <div class="flex items-center gap-4 text-sm">
        <span v-if="joinedName" class="text-[#59605D]">Viewing as <strong class="text-[#202423]">{{ joinedName }}</strong></span>
        <Link href="/recordings" class="text-[#59605D] hover:text-[#006849] font-medium">Recordings</Link>
      </div>
    </header>

    <main class="flex-1 grid lg:grid-cols-[1fr_360px] gap-5 p-6 max-w-[1440px] w-full mx-auto">
      <section class="flex flex-col gap-3 min-w-0">
        <div
          class="relative aspect-video w-full rounded-2xl overflow-hidden border flex items-center justify-center shadow-sm"
          :class="!(hasRemoteStream && hasFrames) && !liveSlide ? 'bg-[#DCD3F7] border-[#C9BCEE]' : 'bg-[#202423] border-[#353A39]'"
        >
          <video
            v-show="hasRemoteStream && hasFrames"
            ref="liveVideoEl"
            @loadeddata="onVideoFrames"
            @resize="onVideoFrames"
            @playing="onVideoFrames"
            autoplay
            playsinline
            :muted="muted"
            class="w-full h-full object-contain bg-[#161918]"
          />
          <canvas
            v-show="!(hasRemoteStream && hasFrames) && liveSlide"
            ref="slideCanvasEl"
            class="max-w-full max-h-full object-contain"
          />
          <div v-if="!(hasRemoteStream && hasFrames) && !liveSlide" class="text-center px-8 py-10 max-w-lg">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/80 px-3 py-1 text-xs font-semibold text-[#006849] mb-4">
              {{ isLive ? 'Connecting to broadcast' : 'Starting soon' }}
            </span>
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-[#202423]">
              {{ isLive ? 'Connecting to live studio broadcast...' : room.title }}
            </h2>
            <p class="text-sm text-[#3E4442] mt-2">
              {{ isLive ? `Stream status: ${streamState}` : 'Waiting for the presenter to go live. Slides and speaker video will appear here automatically.' }}
            </p>
          </div>
          <button
            v-if="hasRemoteStream && hasFrames"
            type="button"
            @click="toggleMute"
            class="absolute bottom-3 right-3 rounded-xl bg-[#202423]/90 hover:bg-[#202423] border border-white/15 px-3.5 py-1.5 text-xs font-semibold text-white"
          >
            {{ muted ? 'Unmute audio' : 'Mute audio' }}
          </button>
        </div>

        <div class="flex items-center justify-between text-xs text-[#59605D] px-1">
          <div>
            <span v-if="liveSlide">Deck: <strong class="text-[#202423]">{{ liveSlide.name }}</strong></span>
            <span v-if="slideTotalPages > 0" class="ml-2">Page {{ slidePage }} / {{ slideTotalPages }}</span>
            <span v-if="slideError" class="ml-2 text-[#C43D3D]">{{ slideError }}</span>
          </div>
          <a v-if="liveSlide" :href="`/r/${room.slug}/slides/${liveSlide.id}`" target="_blank" class="text-[#006849] font-semibold hover:underline">
            Download deck
          </a>
        </div>
      </section>

      <aside class="rounded-2xl border border-[#E6E2D8] bg-white flex flex-col max-h-[calc(100vh-130px)] shadow-sm">
        <div class="px-4 py-3.5 border-b border-[#E6E2D8] font-semibold text-sm text-[#202423] flex items-center justify-between">
          <span>Audience Chat</span>
          <span class="text-xs font-normal text-[#59605D]">{{ messages.length }} messages</span>
        </div>

        <div v-if="pinned.length" class="px-3.5 py-2.5 bg-[#C9F284]/35 border-b border-[#E6E2D8] space-y-1">
          <div v-for="m in pinned" :key="'p-' + m.id" class="text-xs">
            <span class="text-[#006849] font-semibold">Pinned · {{ m.author_name }}:</span>
            <span class="text-[#202423] ml-1">{{ m.body }}</span>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-3.5 space-y-2.5 text-sm">
          <p v-if="!messages.length" class="text-[#787F7B] text-xs text-center py-8">No messages yet. Say hello!</p>
          <div v-for="m in messages" :key="m.id" class="rounded-xl bg-[#FAF8F2] border border-[#EFECE4] px-3 py-2">
            <div class="flex items-center gap-2 text-xs">
              <span class="font-semibold" :class="m.is_presenter ? 'text-[#006849]' : 'text-[#202423]'">{{ m.author_name }}</span>
              <span v-if="m.is_presenter" class="rounded-full bg-[#C9F284] text-[#202423] px-2 py-0.5 text-[10px] font-semibold">Host</span>
            </div>
            <p class="text-[#2E3331] mt-0.5 break-words">{{ m.body }}</p>
          </div>
        </div>

        <div class="p-3 border-t border-[#E6E2D8] bg-[#FAF8F2]/60 rounded-b-2xl">
          <form v-if="!joinedName" @submit.prevent="joinRoom" class="flex gap-2">
            <input v-model="nameInput" type="text" placeholder="Your display name" maxlength="60" class="flex-1 rounded-xl bg-white border border-[#DCD8CE] px-3 py-2 text-sm text-[#202423] placeholder:text-[#8E9591] focus:border-[#006849] focus:outline-none" />
            <button type="submit" class="rounded-xl bg-[#006849] hover:bg-[#00553B] px-3.5 py-2 text-sm font-semibold text-white">Join</button>
          </form>
          <form v-else @submit.prevent="sendMessage" class="flex gap-2">
            <input v-model="chatBody" type="text" placeholder="Say something..." maxlength="500" class="flex-1 rounded-xl bg-white border border-[#DCD8CE] px-3 py-2 text-sm text-[#202423] placeholder:text-[#8E9591] focus:border-[#006849] focus:outline-none" />
            <button type="submit" class="rounded-xl bg-[#006849] hover:bg-[#00553B] px-3.5 py-2 text-sm font-semibold text-white">Send</button>
          </form>
          <p v-if="chatError" class="text-xs text-[#C43D3D] mt-1">{{ chatError }}</p>
        </div>
      </aside>
    </main>
  </div>
</template>
