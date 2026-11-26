<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import GreenroomLogo from '../Components/GreenroomLogo.vue'

defineProps<{
  room: {
    slug: string
    title: string
    description: string | null
    scheduled_at: string | null
    live_ended_at: string | null
  }
  registrant: {
    name: string
    email: string
  }
  recording: {
    id: number
    mime: string
    size: number
    video_url: string
    created_at: string | null
  } | null
}>()

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <Head :title="`Replay — ${room.title}`" />
  <main class="gr-page flex flex-col">
    <header class="gr-topbar">
      <nav class="gr-shell flex items-center justify-between py-4">
        <Link href="/">
          <GreenroomLogo subtitle="Webinar Replay" compact />
        </Link>
        <Link :href="`/r/${room.slug}/register`" class="gr-btn text-xs">
          Webinar registration page
        </Link>
      </nav>
    </header>

    <div class="gr-shell py-8 space-y-6">
      <header class="space-y-2">
        <p class="gr-eyebrow">Webinar replay for {{ registrant.name }}</p>
        <h1 class="gr-title text-3xl sm:text-4xl">{{ room.title }}</h1>
        <p v-if="room.description" class="gr-muted max-w-2xl text-sm whitespace-pre-line">
          {{ room.description }}
        </p>
      </header>

      <div v-if="recording" class="gr-card overflow-hidden">
        <video
          :src="recording.video_url"
          controls
          playsinline
          class="w-full aspect-video bg-[#202423]"
        />
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#E5E0D5] px-5 py-3.5 text-xs">
          <span class="gr-muted">Recorded webinar video &middot; {{ formatBytes(recording.size) }}</span>
          <a
            :href="recording.video_url"
            download
            class="gr-btn gr-btn-primary text-xs"
          >
            Download video
          </a>
        </div>
      </div>

      <div v-else class="gr-card p-10 text-center space-y-2">
        <p class="gr-eyebrow">Processing</p>
        <h2 class="gr-section-title">Replay video is not available yet</h2>
        <p class="gr-muted mx-auto max-w-md text-sm">
          Once the host finishes the live webinar and saves the recording, it will appear right here automatically.
        </p>
      </div>
    </div>
  </main>
</template>
