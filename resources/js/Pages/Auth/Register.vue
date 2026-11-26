<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import GreenroomLogo from '../../Components/GreenroomLogo.vue'

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const submit = () => form.post('/register')
</script>

<template>
  <Head title="Create account — Greenroom" />
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
          <p class="gr-eyebrow">Optional host account</p>
          <h1 class="gr-title mt-1 text-3xl">Create an account</h1>
          <p class="gr-muted mt-1 text-sm">
            Accounts are optional. Sign up to manage your upcoming and past webinars, attendee lists, cover cards, emails, and recordings in My events.
          </p>
        </div>

        <form class="gr-card space-y-4 p-6" @submit.prevent="submit">
          <div>
            <label class="gr-label" for="name">Display name</label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              maxlength="80"
              required
              class="gr-input"
            />
            <p v-if="form.errors.name" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.name }}</p>
          </div>

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
            <label class="gr-label" for="password">Password (min 8 chars)</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              minlength="8"
              required
              class="gr-input"
            />
            <p v-if="form.errors.password" class="mt-1 text-xs text-[#C43D3D]">{{ form.errors.password }}</p>
          </div>

          <div>
            <label class="gr-label" for="password_confirmation">Confirm password</label>
            <input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              autocomplete="new-password"
              minlength="8"
              required
              class="gr-input"
            />
          </div>

          <button
            type="submit"
            class="gr-btn gr-btn-primary w-full justify-center py-2.5 text-sm disabled:opacity-50"
            :disabled="form.processing"
          >
            Create account
          </button>

          <p class="gr-muted text-center text-xs">
            Already have an account?
            <Link href="/login" class="font-semibold text-[#006849] hover:underline">Sign in</Link>
          </p>
        </form>
      </section>
    </div>
  </main>
</template>
