<script setup lang="ts">
import { ref } from 'vue'

export interface RegistrationRow {
  id: number
  name: string
  email: string
  registration_type: string
  attended_at: string | null
  replay_followup_sent_at: string | null
  created_at: string | null
  replay_url: string
}

export interface EmailLogRow {
  id: number
  template_key: string
  recipient_name: string
  recipient_email: string
  subject: string
  body_html: string
  video_url: string | null
  has_recording: boolean
  status: string
  delivery_note: string | null
  sent_at: string | null
}

defineProps<{
  registrations: RegistrationRow[]
  logs: EmailLogRow[]
  mailerMode: string
}>()

const tab = ref<'registrants' | 'logs'>('registrants')
const expandedLogId = ref<number | null>(null)

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function toggleLog(id: number): void {
  expandedLogId.value = expandedLogId.value === id ? null : id
}
</script>

<template>
  <section class="gr-card p-5 sm:p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="inline-flex rounded-xl border border-[#E5E0D5] bg-[#F2EFE6] p-1">
        <button
          type="button"
          class="rounded-lg px-3.5 py-1.5 text-sm font-semibold transition"
          :class="
            tab === 'registrants'
              ? 'bg-[#006849] text-white shadow-xs'
              : 'text-[#5C6260] hover:text-[#202423]'
          "
          @click="tab = 'registrants'"
        >
          Registrants ({{ registrations.length }})
        </button>
        <button
          type="button"
          class="rounded-lg px-3.5 py-1.5 text-sm font-semibold transition"
          :class="
            tab === 'logs'
              ? 'bg-[#006849] text-white shadow-xs'
              : 'text-[#5C6260] hover:text-[#202423]'
          "
          @click="tab = 'logs'"
        >
          Sent &amp; Outbox ({{ logs.length }})
        </button>
      </div>
      <span class="gr-badge-lilac">
        Delivery:
        {{ mailerMode === 'outbox' ? 'In-app outbox (configure SMTP to send externally)' : mailerMode }}
      </span>
    </div>

    <div v-if="tab === 'registrants'" class="overflow-x-auto">
      <p v-if="!registrations.length" class="py-8 text-center text-sm text-[#5C6260]">
        No registrants yet. Share your registration page link to capture attendee emails.
      </p>
      <table v-else class="w-full text-left text-sm">
        <thead class="border-b border-[#E5E0D5] text-[11px] font-bold uppercase tracking-wider text-[#5C6260]">
          <tr>
            <th class="pb-2.5 pr-4">Name</th>
            <th class="pb-2.5 pr-4">Email</th>
            <th class="pb-2.5 pr-4">Type</th>
            <th class="pb-2.5 pr-4">Attended</th>
            <th class="pb-2.5 pr-4">Registered</th>
            <th class="pb-2.5">Replay link</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-[#E5E0D5]">
          <tr v-for="reg in registrations" :key="reg.id" class="hover:bg-[#FAF8F2]">
            <td class="py-3 pr-4 font-semibold text-[#202423]">{{ reg.name }}</td>
            <td class="py-3 pr-4 text-[#5C6260]">{{ reg.email }}</td>
            <td class="py-3 pr-4">
              <span
                :class="reg.registration_type === 'live' ? 'gr-badge' : 'gr-badge-lilac'"
              >
                {{ reg.registration_type === 'live' ? 'Live' : 'Replay' }}
              </span>
            </td>
            <td class="py-3 pr-4 text-[#5C6260]">
              {{ reg.attended_at ? fmtDate(reg.attended_at) : '—' }}
            </td>
            <td class="py-3 pr-4 text-[#5C6260]">{{ fmtDate(reg.created_at) }}</td>
            <td class="py-3">
              <a
                :href="reg.replay_url"
                target="_blank"
                rel="noopener"
                class="text-xs font-semibold text-[#006849] underline-offset-2 hover:underline"
              >
                Open replay
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="space-y-2.5">
      <p v-if="!logs.length" class="py-8 text-center text-sm text-[#5C6260]">
        No emails dispatched yet. Register a test attendee or trigger reminders/follow-ups above.
      </p>
      <div
        v-for="log in logs"
        :key="log.id"
        class="gr-card-soft overflow-hidden transition hover:border-[#006849]/35"
      >
        <button
          type="button"
          class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left"
          @click="toggleLog(log.id)"
        >
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold text-[#202423]">{{ log.subject }}</span>
              <span class="gr-badge">{{ log.template_key }}</span>
              <span v-if="log.has_recording" class="gr-badge-lilac">
                Video attached
              </span>
            </div>
            <p class="mt-0.5 text-xs text-[#5C6260]">
              To {{ log.recipient_name }} &lt;{{ log.recipient_email }}&gt; · {{ fmtDate(log.sent_at) }}
            </p>
          </div>
          <div class="flex items-center gap-2">
            <span
              class="gr-badge"
              :class="log.status === 'sent' ? '' : 'gr-badge-warn'"
            >
              {{ log.status }}
            </span>
            <span class="text-xs font-medium text-[#006849]">
              {{ expandedLogId === log.id ? 'Hide' : 'Inspect' }}
            </span>
          </div>
        </button>

        <div
          v-if="expandedLogId === log.id"
          class="border-t border-[#E5E0D5] bg-[#FAF8F2] px-4 py-3.5 text-sm"
        >
          <p v-if="log.delivery_note" class="mb-2 text-xs text-[#5C6260]">
            Note: {{ log.delivery_note }}
          </p>
          <div
            class="prose prose-sm max-w-none rounded-xl border border-[#E5E0D5] bg-white p-4 text-[#202423]"
            v-html="log.body_html"
          />
          <p v-if="log.video_url" class="mt-2.5 text-xs text-[#006849] font-medium">
            Recording URL:
            <a :href="log.video_url" target="_blank" rel="noopener" class="underline">
              {{ log.video_url }}
            </a>
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
