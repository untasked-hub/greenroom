<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import GreenroomLogo from '../../Components/GreenroomLogo.vue'

const form = useForm({
  email: '',
  password: '',
})

const submit = () => form.post('/login')
</script>

<template>
  <Head title="Sign in — Greenroom" />
  <main class="gr-page flex flex-col">
    <header class="gr-topbar">
      <nav class="gr-shell flex items-center justify-between py-4">
        <Link href="/">
          <GreenroomLogo compact />
        </Link>
        <Link href="/" class="gr-btn text-xs">&larr; Back</Link>
      </nav>
    </header>

    <div class="gr-shell flex flex-1 items-center justify-center py-12">
      <section class="w-full max-w-md space-y-6">
        <div>
          <p class="gr-eyebrow">Host account</p>
          <h1 class="gr-title mt-1 text-3xl">Sign in</h1>
          <p class="gr-muted mt-1 text-sm">Access your upcoming and past events, attendee lists, email settings, and studios.</p>
        </div>

        <p v-if="$page.props.errors.billing" role="alert" class="text-sm text-[#C43D3D]">{{ $page.props.errors.billing }}</p>

        <form class="gr-card space-y-4 p-6" @submit.prevent="submit">
          <div>
            <label class="gr-label" for="email">Email</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              autocomplete="email"
              required
              class="gr-input"
            />
            <p v-if="form.errors.email" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.email }}</p>
          </div>

          <div>
            <label class="gr-label" for="password">Password</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              required
              class="gr-input"
            />
            <p v-if="form.errors.password" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.password }}</p>
          </div>

          <button
            type="submit"
            class="gr-btn gr-btn-primary w-full justify-center py-2.5 text-sm disabled:opacity-50"
            :disabled="form.processing"
          >
            Sign in
          </button>

          <p class="gr-muted text-center text-xs">
            No account yet?
            <Link href="/register" class="font-semibold text-[#006849] hover:underline">{{ $page.props.saasEnabled ? 'Create an account' : 'Create an optional account' }}</Link>
          </p>
        </form>
      </section>
    </div>
  </main>
</template>
