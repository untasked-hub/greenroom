<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import GreenroomLogo from '../Components/GreenroomLogo.vue'

defineProps<{ title: string }>()

const page = usePage()
const user = computed(() => (page.props.auth as { user: { name: string; email: string } | null } | undefined)?.user ?? null)

const form = useForm({
  title: '',
  scheduled_at: '',
  description: '',
})
const create = () => {
  const scheduled = form.scheduled_at ? new Date(form.scheduled_at).toISOString() : ''
  form.transform((data) => ({
    ...data,
    scheduled_at: scheduled || null,
  })).post('/rooms')
}
const logout = () => router.post('/logout')
</script>

<template>
  <Head :title="`${title} — Live Webinar Studio`" />
  <main class="gr-page flex flex-col">
    <header class="gr-topbar">
      <nav class="gr-shell flex items-center justify-between gap-4 py-4">
        <Link href="/">
          <GreenroomLogo subtitle="Live Webinar Studio" />
        </Link>
        <div v-if="user" class="flex items-center gap-2.5 text-sm">
          <span class="gr-muted hidden sm:inline">{{ user.name }}</span>
          <Link href="/events" class="gr-btn text-xs">
            My events
          </Link>
          <button type="button" class="gr-muted text-xs hover:text-[#202423]" @click="logout">
            Sign out
          </button>
        </div>
        <div v-else class="flex items-center gap-2.5 text-sm">
          <Link href="/login" class="gr-btn text-xs">Sign in</Link>
          <Link href="/register" class="gr-btn gr-btn-primary text-xs">
            Create account
          </Link>
        </div>
      </nav>
    </header>

    <div class="gr-shell flex flex-1 items-center py-10 lg:py-14">
      <div class="grid w-full items-center gap-8 lg:grid-cols-12">
        <section class="space-y-6 lg:col-span-6">
          <div class="inline-flex items-center gap-2 rounded-full bg-[#C9F284] px-3 py-1 text-xs font-bold text-[#006849]">
            <span class="h-2 w-2 rounded-full bg-[#006849]" />
            Browser-native webinar studio
          </div>
          <div class="space-y-3">
            <h1 class="gr-title text-4xl sm:text-5xl">
              Host calm, crisp webinars from your browser.
            </h1>
            <p class="gr-muted max-w-xl text-base leading-relaxed">
              Prepare slides, screen share, and speaker camera backstage, collect registrations on a clean public page,
              and send automated event emails in 10 languages.
            </p>
          </div>
          <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-[#B5DF6C] bg-[#C9F284]/45 p-3.5">
              <p class="text-xs font-bold text-[#006849]">Live Stage</p>
              <p class="gr-muted mt-1 text-xs">PDF &amp; PPTX slides, screen share, camera overlay &amp; chat.</p>
            </div>
            <div class="rounded-xl border border-[#C6BCE8] bg-[#DCD3F7]/45 p-3.5">
              <p class="text-xs font-bold text-[#202423]">10-Language Emails</p>
              <p class="gr-muted mt-1 text-xs">DB-backed defaults + TipTap editor &amp; replay video links.</p>
            </div>
            <div class="gr-card-soft p-3.5">
              <p class="text-xs font-bold text-[#006849]">Instant Replays</p>
              <p class="gr-muted mt-1 text-xs">Auto-recorded sessions ready for registrants &amp; hosts.</p>
            </div>
          </div>
        </section>

        <section class="lg:col-span-6">
          <form class="gr-card space-y-4 p-6 sm:p-7" @submit.prevent="create">
            <div>
              <p class="gr-eyebrow">New Webinar</p>
              <h2 class="gr-section-title mt-0.5">Open a backstage studio</h2>
            </div>

            <div>
              <label class="gr-label" for="room-title">Name your webinar</label>
              <input
                id="room-title"
                v-model="form.title"
                class="gr-input"
                placeholder="Spring Product Launch AMA"
                maxlength="120"
                required
              />
            </div>

            <div class="grid gap-3.5 sm:grid-cols-2">
              <div>
                <label class="gr-label" for="room-scheduled-at">
                  Scheduled go-live <span class="gr-muted font-normal">(optional)</span>
                </label>
                <input
                  id="room-scheduled-at"
                  v-model="form.scheduled_at"
                  type="datetime-local"
                  class="gr-input"
                />
              </div>
              <div>
                <label class="gr-label" for="room-description">
                  Short description <span class="gr-muted font-normal">(optional)</span>
                </label>
                <input
                  id="room-description"
                  v-model="form.description"
                  class="gr-input"
                  placeholder="What attendees will learn"
                  maxlength="300"
                />
              </div>
            </div>

            <p v-if="form.errors.title || form.errors.scheduled_at || form.errors.description" class="text-xs text-[#C43D3D]">
              {{ form.errors.title || form.errors.scheduled_at || form.errors.description }}
            </p>

            <button
              type="submit"
              class="gr-btn gr-btn-primary w-full justify-center py-2.5 text-sm disabled:opacity-50"
              :disabled="form.processing"
            >
              {{ form.processing ? 'Opening studio…' : 'Open a studio' }}
            </button>

            <p class="gr-muted text-xs leading-relaxed">
              Includes a private studio link, public registration page, automated event emails, and live guest link.
              <span v-if="user"> Manage all your upcoming and past webinars, attendees, covers, emails, and recordings in My events.</span>
              <span v-else>
                Optional:
                <Link href="/register" class="font-semibold text-[#006849] underline decoration-[#006849]/40 hover:text-[#00543B]">
                  create an account
                </Link>
                to manage your events, attendee lists, and past webinar recordings.
              </span>
            </p>
          </form>
        </section>
      </div>
    </div>
  </main>
</template>
