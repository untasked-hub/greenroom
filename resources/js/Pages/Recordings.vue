<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import GreenroomLogo from '../Components/GreenroomLogo.vue'

interface RecordingItem {
  id: number
  room_title: string
  room_slug: string
  size: number
  mime: string
  started_at: string | null
  ended_at: string | null
  created_at: string | null
  video_url: string
}

defineProps<{
  recordings: RecordingItem[]
}>()

const page = usePage()
const user = computed(() => (page.props.auth as { user: { name: string; email: string } | null } | undefined)?.user ?? null)

function logout() {
  router.post('/logout')
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`
}
</script>

<template>
  <Head title="Recordings — Greenroom" />
  <main class="gr-page flex flex-col">
    <header class="gr-topbar">
      <nav class="gr-shell flex flex-wrap items-center justify-between gap-4 py-4">
        <Link href="/">
          <GreenroomLogo subtitle="Host Archive" compact />
        </Link>
        <div class="flex items-center gap-3 text-sm">
          <Link href="/" class="gr-btn gr-btn-primary text-xs">
            New webinar
          </Link>
          <button type="button" class="gr-btn text-xs" @click="logout">
            Sign out
          </button>
        </div>
      </nav>
    </header>

    <div class="gr-shell py-8 space-y-6">
      <header class="space-y-1">
        <p class="gr-eyebrow">Greenroom Archive</p>
        <h1 class="gr-title text-3xl">Last 3 live webinar recordings</h1>
        <p v-if="user" class="gr-muted text-sm">
          Signed in as <strong class="text-[#202423]">{{ user.name }}</strong> ({{ user.email }})
        </p>
      </header>

      <section v-if="recordings.length === 0" class="gr-card p-10 text-center space-y-3">
        <p class="gr-eyebrow">Archive ready</p>
        <h2 class="gr-section-title">No live webinar recordings yet</h2>
        <p class="gr-muted text-sm max-w-md mx-auto">
          Start a new webinar while signed in, click <strong class="text-[#202423]">Go live</strong> in the presenter studio, and click <strong class="text-[#202423]">End live</strong> when finished. Your 3 most recent recordings will appear here automatically.
        </p>
        <div class="pt-2">
          <Link href="/" class="gr-btn gr-btn-primary text-sm">
            Open a studio
          </Link>
        </div>
      </section>

      <section v-else class="grid gap-6">
        <article
          v-for="(rec, idx) in recordings"
          :key="rec.id"
          class="gr-card overflow-hidden"
        >
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#E5E0D5] px-5 py-3.5">
            <div class="flex flex-wrap items-center gap-2">
              <span class="gr-badge">#{{ idx + 1 }}</span>
              <h2 class="text-base font-semibold text-[#202423]">{{ rec.room_title }}</h2>
              <span class="gr-muted text-xs">/r/{{ rec.room_slug }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs">
              <span class="gr-muted">Recorded {{ formatDate(rec.started_at || rec.created_at) }}</span>
              <span class="gr-muted">&middot;</span>
              <span class="gr-muted">{{ formatSize(rec.size) }}</span>
              <a :href="rec.video_url" download class="gr-btn text-xs">
                Download
              </a>
            </div>
          </div>
          <div class="bg-[#202423] p-2">
            <video
              :src="rec.video_url"
              controls
              playsinline
              preload="metadata"
              class="mx-auto aspect-video w-full max-w-3xl rounded-lg bg-[#202423]"
            />
          </div>
        </article>
      </section>
    </div>
  </main>
</template>
