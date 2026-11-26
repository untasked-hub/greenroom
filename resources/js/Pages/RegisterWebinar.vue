<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GreenroomLogo from '../Components/GreenroomLogo.vue'
import EventCoverCard from '../Components/EventCoverCard.vue'

interface PublicRoom {
  slug: string
  title: string
  description: string | null
  cover_theme?: string
  cover_badge?: string | null
  cover_image_url?: string | null
  scheduled_at: string | null
  is_live: boolean
  has_ended: boolean
  live_ended_at: string | null
  has_recording: boolean
}

interface ExistingRegistration {
  name: string
  email: string
  registration_type: 'live' | 'replay'
  join_url: string
  replay_url: string
}

const props = defineProps<{
  room: PublicRoom
  registration: ExistingRegistration | null
  defaultType: 'live' | 'replay'
}>()

const form = useForm({
  name: props.registration?.name ?? '',
  email: props.registration?.email ?? '',
  registration_type: props.defaultType,
})

const formattedSchedule = computed(() => {
  if (!props.room.scheduled_at) return null
  try {
    return new Date(props.room.scheduled_at).toLocaleString(undefined, {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
    })
  } catch {
    return props.room.scheduled_at
  }
})

const statusLabel = computed(() => {
  if (props.room.is_live) return 'Live now'
  if (props.room.has_ended) return props.room.has_recording ? 'Replay available' : 'Ended'
  if (props.room.scheduled_at) return 'Scheduled webinar'
  return 'Open registration'
})

const submit = () => {
  form.post(`/r/${props.room.slug}/register`)
}
</script>

<template>
  <Head :title="`Register — ${room.title}`" />
  <main class="gr-page flex flex-col">
    <header class="gr-topbar">
      <nav class="gr-shell flex items-center justify-between py-4">
        <Link href="/">
          <GreenroomLogo subtitle="Webinar Registration" compact />
        </Link>
        <Link :href="`/r/${room.slug}`" class="gr-btn text-xs">
          Go to webinar room &rarr;
        </Link>
      </nav>
    </header>

    <div class="gr-shell flex flex-1 items-center justify-center py-10">
      <section class="w-full max-w-2xl space-y-6">
        <EventCoverCard
          :title="room.title"
          :description="room.description"
          :theme="room.cover_theme || 'pistachio'"
          :badge="room.cover_badge || statusLabel"
          :image-url="room.cover_image_url || null"
          :scheduled-at="room.scheduled_at"
        />

        <header class="space-y-3">
          <div class="flex flex-wrap items-center gap-2.5">
            <span
              :class="room.is_live ? 'gr-badge-red' : 'gr-badge'"
            >
              {{ statusLabel }}
            </span>
            <span v-if="formattedSchedule" class="gr-muted text-xs">
              {{ formattedSchedule }}
            </span>
          </div>
          <h1 class="gr-title text-3xl sm:text-4xl">{{ room.title }}</h1>
          <p v-if="room.description" class="gr-muted leading-relaxed whitespace-pre-line">
            {{ room.description }}
          </p>
        </header>

        <div
          v-if="registration"
          class="gr-card border-[#006849]/35 bg-[#C9F284]/25 p-6 space-y-4"
        >
          <div>
            <p class="gr-eyebrow">You&apos;re registered</p>
            <h2 class="mt-1 text-xl font-bold text-[#202423]">
              Confirmation sent to {{ registration.email }}
            </h2>
            <p class="gr-muted mt-1 text-sm">
              <span v-if="registration.registration_type === 'replay'">
                Your replay confirmation email includes a link to watch the recorded webinar.
              </span>
              <span v-else>
                We&apos;ve saved your seat and queued a 10-minute reminder before we go live.
              </span>
            </p>
          </div>

          <div class="flex flex-wrap gap-3">
            <a
              v-if="registration.registration_type === 'live'"
              :href="registration.join_url"
              class="gr-btn gr-btn-primary text-sm"
            >
              Enter webinar room
            </a>
            <a
              v-if="room.has_recording || registration.registration_type === 'replay'"
              :href="registration.replay_url"
              class="gr-btn text-sm"
            >
              Open replay page
            </a>
          </div>
        </div>

        <form class="gr-card space-y-5 p-6" @submit.prevent="submit">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="gr-eyebrow">Attendee details</p>
              <h2 class="gr-section-title mt-0.5">
                {{ form.registration_type === 'replay' ? 'Register for the replay' : 'Reserve your spot' }}
              </h2>
            </div>
            <div class="inline-flex rounded-xl border border-[#E5E0D5] bg-[#F2EFE6] p-1 text-xs">
              <button
                type="button"
                class="rounded-lg px-3 py-1.5 font-semibold transition"
                :class="form.registration_type === 'live' ? 'bg-[#006849] text-white' : 'text-[#5C6260] hover:text-[#202423]'"
                @click="form.registration_type = 'live'"
              >
                Live session
              </button>
              <button
                type="button"
                class="rounded-lg px-3 py-1.5 font-semibold transition"
                :class="form.registration_type === 'replay' ? 'bg-[#006849] text-white' : 'text-[#5C6260] hover:text-[#202423]'"
                @click="form.registration_type = 'replay'"
              >
                Watch replay
              </button>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="gr-label" for="reg-name">Your name</label>
              <input
                id="reg-name"
                v-model="form.name"
                type="text"
                required
                maxlength="120"
                placeholder="Alex Rivera"
                class="gr-input"
              />
              <p v-if="form.errors.name" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="gr-label" for="reg-email">Email address</label>
              <input
                id="reg-email"
                v-model="form.email"
                type="email"
                required
                maxlength="190"
                placeholder="alex@example.com"
                class="gr-input"
              />
              <p v-if="form.errors.email" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.email }}</p>
            </div>
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="gr-btn gr-btn-primary w-full justify-center py-2.5 text-sm disabled:opacity-50"
          >
            {{ form.registration_type === 'replay' ? 'Register & watch replay' : 'Register for live webinar' }}
          </button>

          <p class="gr-muted text-xs">
            <span v-if="form.registration_type === 'replay'">
              You&apos;ll receive a replay confirmation email with the recorded webinar video link.
            </span>
            <span v-else>
              You&apos;ll receive a registration confirmation, a reminder 10 minutes before the webinar starts, and a follow-up after it ends.
            </span>
          </p>
        </form>
      </section>
    </div>
  </main>
</template>
