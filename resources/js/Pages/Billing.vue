<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import GreenroomLogo from '../Components/GreenroomLogo.vue'

type Plan = 'pass' | 'studio_monthly' | 'studio_yearly' | 'extra_event'

const props = defineProps<{
  enabled: boolean
  signedIn: boolean
  prices: Record<Plan, number>
  checkoutReady: Record<Plan, boolean>
  portalReady: boolean
  studio: { plan: Plan; status: string; period_end: string | null } | null
  usage: { rooms: number; passes: number; extras: number }
  limits: { attendees: number; downloadDays: number; rooms: number; recordings: number; durationMinutes: number }
  checkoutReturned: boolean
}>()

const checkout = useForm({ plan: 'pass' as Plan })
const portal = useForm({})
const buy = (plan: Plan) => {
  if (props.checkoutReady[plan]) {
    checkout.plan = plan
    checkout.post('/billing/checkout')
  }
}
</script>

<template>
  <Head title="Plans & billing — Greenroom" />
  <main class="gr-page min-h-screen">
    <header class="gr-topbar">
      <nav class="gr-shell flex items-center justify-between gap-4 py-4">
        <Link href="/"><GreenroomLogo /></Link>
        <Link :href="signedIn ? '/events' : '/'" class="gr-btn text-sm">
          {{ signedIn ? 'My events' : 'Back to Greenroom' }}
        </Link>
      </nav>
    </header>

    <div class="gr-shell space-y-8 py-12">
      <div>
        <h1 class="gr-title text-4xl sm:text-5xl">Plans &amp; billing</h1>
        <p v-if="!enabled" class="gr-muted mt-3">This Greenroom instance is self-hosted. You can create webinars for free.</p>
      </div>

      <template v-if="enabled">
        <p v-if="$page.props.errors.billing" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900">
          {{ $page.props.errors.billing }}
        </p>
        <p v-if="checkoutReturned" role="status" class="gr-card-soft p-4">
          Your hosted webinar allowance updates after Stripe confirms checkout.
        </p>
        <section v-if="signedIn" class="gr-card flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6">
          <div>
            <h2 class="gr-section-title">{{ studio ? (studio.plan === 'studio_yearly' ? 'Studio yearly' : 'Studio monthly') : 'Your hosted webinars' }}</h2>
            <p class="gr-muted mt-2 text-sm">
              <span v-if="studio">{{ usage.rooms }} of {{ limits.rooms }} Studio webinars used this month · </span>
              {{ usage.passes }} passes available · {{ usage.extras }} extra webinars available
            </p>
          </div>
          <button v-if="portalReady" type="button" class="gr-btn" :disabled="portal.processing" @click="portal.post('/billing/portal')">
            Manage billing
          </button>
        </section>

        <p v-if="Object.values(checkoutReady).some((ready) => !ready)" class="gr-muted text-sm">
          Some hosted purchases are unavailable until Stripe setup is complete.
        </p>
      </template>

      <div class="grid items-stretch gap-5 md:grid-cols-3">
        <section class="gr-card flex flex-col gap-5 p-6">
          <h2 class="gr-section-title">Self-hosted</h2>
          <p class="gr-title text-4xl">Free</p>
          <p class="gr-muted">Run Greenroom on your own infrastructure.</p>
          <Link v-if="!enabled" href="/" class="gr-btn mt-auto self-start">Create a webinar</Link>
        </section>

        <template v-if="enabled">
          <section class="gr-card flex flex-col gap-5 p-6">
            <h2 class="gr-section-title">Webinar Pass</h2>
            <p class="gr-title text-4xl">€{{ prices.pass }} <span class="gr-muted text-sm font-normal">one time</span></p>
            <ul class="gr-muted list-disc space-y-2 pl-5 text-sm">
              <li>One hosted webinar</li>
              <li>Up to {{ limits.attendees }} registrations and {{ limits.durationMinutes / 60 }} hours live</li>
              <li>{{ limits.downloadDays }} days to download your recording</li>
            </ul>
            <Link v-if="!signedIn" href="/login" class="gr-btn mt-auto self-start">Sign in to buy</Link>
            <button v-else type="button" class="gr-btn mt-auto self-start disabled:opacity-50" :disabled="checkout.processing || !checkoutReady.pass" @click="buy('pass')">
              Buy a pass
            </button>
          </section>
          <section class="gr-card flex flex-col gap-5 border-[#8caf52] bg-[#f5f9eb] p-6">
            <h2 class="gr-section-title">Studio</h2>
            <p class="gr-title text-4xl">€{{ prices.studio_monthly }} <span class="gr-muted text-sm font-normal">/ month</span></p>
            <p class="gr-muted -mt-3 text-sm">€{{ prices.studio_yearly }} per year with annual billing</p>
            <ul class="gr-muted list-disc space-y-2 pl-5 text-sm">
              <li>{{ limits.rooms }} hosted webinars each calendar month</li>
              <li>Up to {{ limits.attendees }} registrations per webinar</li>
              <li>Download your latest {{ limits.recordings }} Studio recordings</li>
              <li>Extra webinars for €{{ prices.extra_event }} each</li>
            </ul>
            <Link v-if="!signedIn" href="/login" class="gr-btn gr-btn-primary mt-auto self-start">Sign in to subscribe</Link>
            <div v-else class="mt-auto flex flex-wrap gap-2">
              <button v-if="studio" type="button" class="gr-btn gr-btn-primary disabled:opacity-50" :disabled="checkout.processing || !checkoutReady.extra_event" @click="buy('extra_event')">
                Buy an extra webinar
              </button>
              <template v-else>
                <button type="button" class="gr-btn gr-btn-primary disabled:opacity-50" :disabled="checkout.processing || !checkoutReady.studio_monthly" @click="buy('studio_monthly')">Choose monthly</button>
                <button type="button" class="gr-btn disabled:opacity-50" :disabled="checkout.processing || !checkoutReady.studio_yearly" @click="buy('studio_yearly')">Choose yearly</button>
              </template>
            </div>
          </section>
        </template>
      </div>
    </div>
  </main>
</template>
