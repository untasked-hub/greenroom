<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import GreenroomLogo from '../Components/GreenroomLogo.vue'
import EmailTemplateEditorModal, { type EmailTemplateItem } from '../Components/EmailTemplateEditorModal.vue'
import StudioEmailLogsPanel, { type EmailLogRow, type RegistrationRow } from '../Components/StudioEmailLogsPanel.vue'
import type { EmailPlaceholder } from '../Components/TipTapEmailEditor.vue'

interface RoomProps {
  slug: string
  title: string
  description: string
  email_language: string
  scheduled_at: string | null
  is_live: boolean
  has_ended: boolean
  live_ended_at: string | null
  reminder_dispatched_at: string | null
  followup_dispatched_at: string | null
  has_recording: boolean
  recording_size: number | null
}

interface LanguageOption {
  code: string
  name: string
  native_name: string
  direction: 'ltr' | 'rtl'
}

const props = defineProps<{
  room: RoomProps
  presenterKey: string
  registrationUrl: string
  guestUrl: string
  studioUrl: string
  templates: EmailTemplateItem[]
  registrations: RegistrationRow[]
  logs: EmailLogRow[]
  mailerMode: string
  languages: LanguageOption[]
  emailLanguage: string
  placeholders: EmailPlaceholder[]
}>()

const templateList = ref<EmailTemplateItem[]>([...props.templates])
const currentLanguage = ref<string>(props.emailLanguage || props.room.email_language || 'en')

watch(
  () => props.templates,
  (next) => {
    templateList.value = [...next]
    if (editingTemplate.value) {
      const refreshed = next.find((t) => t.key === editingTemplate.value?.key)
      if (refreshed) {
        editingTemplate.value = { ...refreshed }
      }
    }
  },
)

watch(
  () => props.emailLanguage || props.room.email_language,
  (next) => {
    if (next) {
      currentLanguage.value = next
    }
  },
)
const savingLanguage = ref(false)
const languageNotice = ref<string | null>(null)
const editingTemplate = ref<EmailTemplateItem | null>(null)
const copied = ref(false)
const dispatching = ref<string | null>(null)
const dispatchMessage = ref<string | null>(null)

function toLocalDatetime(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const scheduleForm = useForm({
  title: props.room.title,
  description: props.room.description ?? '',
  scheduled_at: toLocalDatetime(props.room.scheduled_at),
})

function saveSchedule(): void {
  const payload = {
    title: scheduleForm.title,
    description: scheduleForm.description,
    scheduled_at: scheduleForm.scheduled_at ? new Date(scheduleForm.scheduled_at).toISOString() : null,
  }
  scheduleForm
    .transform(() => payload)
    .post(`/studio/${props.room.slug}/${props.presenterKey}/schedule`, {
      preserveScroll: true,
    })
}

async function copyRegLink(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.registrationUrl)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    copied.value = false
  }
}

function csrfToken(): string {
  const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

const activeLanguageObj = computed(() =>
  props.languages.find((l) => l.code === currentLanguage.value) ?? props.languages[0] ?? null,
)

const activeLanguageLabel = computed(() => {
  const lang = activeLanguageObj.value
  return lang ? `${lang.native_name} (${lang.name})` : currentLanguage.value.toUpperCase()
})

async function changeLanguage(code: string, resetAll = false): Promise<void> {
  if (!code || (!resetAll && code === currentLanguage.value) || savingLanguage.value) return
  savingLanguage.value = true
  languageNotice.value = null
  try {
    const res = await fetch(`/studio/${props.room.slug}/${props.presenterKey}/emails/language`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify({ language: code, reset_all: resetAll }),
    })
    if (!res.ok) throw new Error('language update failed')
    {
      const data = await res.json()
      currentLanguage.value = data.emailLanguage ?? code
      if (Array.isArray(data.templates)) {
        templateList.value = data.templates
        if (editingTemplate.value) {
          const refreshed = (data.templates as EmailTemplateItem[]).find(
            (t) => t.key === editingTemplate.value?.key,
          )
          if (refreshed) {
            editingTemplate.value = { ...refreshed }
          }
        }
      }
      const lang = props.languages.find((l) => l.code === currentLanguage.value)
      const label = lang ? `${lang.native_name} (${lang.name})` : currentLanguage.value
      languageNotice.value = resetAll
        ? `All email templates reset to ${label} defaults.`
        : `Email campaign language saved as ${label} and templates reloaded.`
    }
  } catch {
    languageNotice.value = 'Language could not be updated. Please try again.'
  } finally {
    savingLanguage.value = false
  }
}

async function dispatchNow(kind: 'reminder' | 'followups'): Promise<void> {
  dispatching.value = kind
  dispatchMessage.value = null
  try {
    const res = await fetch(`/studio/${props.room.slug}/${props.presenterKey}/emails/dispatch`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify({ kind }),
    })
    const data = await res.json()
    dispatchMessage.value = `Dispatched ${data.dispatched ?? 0} email(s).`
    router.reload({ only: ['templates', 'logs', 'room'] })
  } finally {
    dispatching.value = null
  }
}

const groups = [
  { id: 'when_registered', title: 'When registered' },
  { id: 'before_start', title: 'Before the webinar starts' },
  { id: 'after_end', title: 'After the webinar ends' },
]

const groupedTemplates = computed(() =>
  groups.map((g) => ({
    ...g,
    items: templateList.value.filter((t) => t.group === g.title || t.group === g.id),
  })),
)

function sentCount(templateKey: string): number {
  return props.logs.filter((l) => l.template_key === templateKey).length
}

function onTemplateSaved(updated: EmailTemplateItem, templates?: EmailTemplateItem[]): void {
  if (Array.isArray(templates) && templates.length > 0) {
    templateList.value = templates
  } else {
    templateList.value = templateList.value.map((t) => (t.key === updated.key ? updated : t))
  }
  if (editingTemplate.value?.key === updated.key) {
    editingTemplate.value = updated
  }
}
</script>

<template>
  <Head :title="`Schedule & Emails — ${room.title}`" />
  <div class="gr-page">
    <header class="gr-topbar">
      <div class="gr-shell flex flex-wrap items-center justify-between gap-3 py-4">
        <div class="flex items-center gap-4">
          <Link href="/">
            <GreenroomLogo subtitle="Schedule & Event Emails" compact />
          </Link>
          <div class="hidden h-6 w-px bg-[#E5E0D5] sm:block" />
          <div class="min-w-0">
            <h1 class="truncate text-base font-bold text-[#202423]">{{ room.title }}</h1>
            <p class="gr-muted text-xs">Customize schedule, language &amp; automated webinar emails</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <Link
            href="/events"
            class="gr-btn text-xs"
          >
            My events
          </Link>
          <a
            :href="registrationUrl"
            target="_blank"
            class="gr-btn text-xs"
          >
            Open Registration Page
          </a>
          <button
            type="button"
            class="gr-btn text-xs"
            @click="copyRegLink"
          >
            {{ copied ? 'Copied!' : 'Copy Registration Link' }}
          </button>
          <Link
            :href="studioUrl"
            class="gr-btn gr-btn-primary text-xs"
          >
            Backstage Studio
          </Link>
        </div>
      </div>
    </header>

    <main class="gr-shell space-y-6 py-6">
      <section class="gr-card p-5 sm:p-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <div>
            <p class="gr-eyebrow">Webinar Schedule</p>
            <h2 class="gr-section-title mt-0.5">Schedule &amp; Registration Page</h2>
          </div>
          <span :class="room.has_recording ? 'gr-badge' : 'gr-badge-warn'">
            {{ room.has_recording ? 'Recording ready for replay emails' : 'No recording yet' }}
          </span>
        </div>
        <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveSchedule">
          <div>
            <label class="gr-label">Webinar title</label>
            <input
              v-model="scheduleForm.title"
              type="text"
              required
              class="gr-input"
            />
          </div>
          <div>
            <label class="gr-label">Scheduled date &amp; time</label>
            <input
              v-model="scheduleForm.scheduled_at"
              type="datetime-local"
              class="gr-input"
            />
          </div>
          <div class="md:col-span-2">
            <label class="gr-label">Registration page description</label>
            <textarea
              v-model="scheduleForm.description"
              rows="2"
              placeholder="What will attendees learn in this webinar?"
              class="gr-input"
            />
          </div>
          <div class="flex flex-wrap items-center gap-3 md:col-span-2">
            <button
              type="submit"
              :disabled="scheduleForm.processing"
              class="gr-btn gr-btn-primary text-sm disabled:opacity-50"
            >
              {{ scheduleForm.processing ? 'Saving…' : 'Save Schedule' }}
            </button>
            <span v-if="scheduleForm.recentlySuccessful" class="text-xs font-semibold text-[#006849]">
              Schedule saved.
            </span>
          </div>
        </form>
      </section>

      <section class="gr-card p-5 sm:p-6">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-4 border-b border-[#E5E0D5] pb-5">
          <div>
            <p class="gr-eyebrow">Automated Event Emails</p>
            <h2 class="gr-section-title mt-0.5">Event Emails &amp; Language</h2>
            <p class="gr-muted mt-1 text-xs">
              Select a language to load its database-stored default translations, then tailor any email with the TipTap editor.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              :disabled="dispatching !== null"
              class="gr-btn text-xs disabled:opacity-50"
              @click="dispatchNow('reminder')"
            >
              {{ dispatching === 'reminder' ? 'Sending…' : 'Send 10-min reminder now' }}
            </button>
            <button
              type="button"
              :disabled="dispatching !== null"
              class="gr-btn text-xs disabled:opacity-50"
              @click="dispatchNow('followups')"
            >
              {{ dispatching === 'followups' ? 'Sending…' : 'Send 30-min follow-ups now' }}
            </button>
          </div>
        </div>

        <div class="gr-card-soft mb-5 flex flex-wrap items-center justify-between gap-4 p-4">
          <div class="min-w-[220px] flex-1">
            <label for="email-language-select" class="gr-label">Email language (10 languages)</label>
            <p class="gr-muted text-xs">
              Default subjects and email copy are loaded from the database for your chosen language.
              Custom edits you save for a template are preserved unless you reset it.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2.5">
            <select
              id="email-language-select"
              :value="currentLanguage"
              :disabled="savingLanguage"
              aria-label="Email language"
              class="gr-input min-w-[220px] cursor-pointer font-medium"
              @change="changeLanguage(($event.target as HTMLSelectElement).value)"
            >
              <option
                v-for="lang in languages"
                :key="lang.code"
                :value="lang.code"
              >
                {{ lang.native_name }} — {{ lang.name }} ({{ lang.code.toUpperCase() }})
              </option>
            </select>
            <span v-if="activeLanguageObj" class="gr-badge">
              {{ savingLanguage ? 'Updating…' : activeLanguageObj.code.toUpperCase() }}
            </span>
            <button
              type="button"
              :disabled="savingLanguage"
              class="gr-btn text-xs disabled:opacity-50"
              title="Replace any customized templates with the selected language defaults"
              @click="changeLanguage(currentLanguage, true)"
            >
              Reset all to {{ activeLanguageObj?.code.toUpperCase() ?? currentLanguage.toUpperCase() }}
            </button>
          </div>
        </div>

        <p
          v-if="languageNotice"
          class="mb-4 rounded-xl border border-[#B5DF6C] bg-[#C9F284]/35 px-3.5 py-2.5 text-xs font-medium text-[#006849]"
        >
          {{ languageNotice }}
        </p>

        <p
          v-if="dispatchMessage"
          class="mb-4 rounded-xl border border-[#B5DF6C] bg-[#C9F284]/35 px-3.5 py-2.5 text-xs font-medium text-[#006849]"
        >
          {{ dispatchMessage }}
        </p>

        <div class="space-y-5">
          <div v-for="group in groupedTemplates" :key="group.id">
            <h3 class="mb-2.5 text-xs font-bold uppercase tracking-wider text-[#006849]">
              {{ group.title }}
            </h3>
            <div class="divide-y divide-[#E5E0D5] overflow-hidden rounded-xl border border-[#E5E0D5] bg-[#FAF8F2]">
              <div
                v-for="tpl in group.items"
                :key="tpl.key"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 transition hover:bg-[#F2EFE6]"
              >
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-bold text-[#202423]">{{ tpl.label }}</span>
                    <span class="gr-badge">{{ tpl.audience }}</span>
                    <span v-if="!tpl.enabled" class="gr-badge-warn">Paused</span>
                  </div>
                  <p class="gr-muted mt-1 truncate text-xs">
                    <span class="font-medium text-[#202423]">{{ tpl.subject }}</span>
                  </p>
                  <p class="gr-muted mt-0.5 text-[11px]">
                    {{ tpl.timing }}
                    <span v-if="tpl.attach_recording" class="ml-2 font-semibold text-[#006849]">
                      · Includes webinar video
                    </span>
                  </p>
                </div>
                <div class="flex items-center gap-3">
                  <span class="gr-muted text-xs">Sent: {{ sentCount(tpl.key) }}</span>
                  <button
                    type="button"
                    class="gr-btn text-xs"
                    @click="editingTemplate = tpl"
                  >
                    Edit email
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <StudioEmailLogsPanel
        :registrations="registrations"
        :logs="logs"
        :mailer-mode="mailerMode"
      />
    </main>

    <EmailTemplateEditorModal
      :template="editingTemplate"
      :room-slug="room.slug"
      :presenter-key="presenterKey"
      :has-recording="room.has_recording"
      :email-language="currentLanguage"
      :language-label="activeLanguageLabel"
      :languages="languages"
      :saving-language="savingLanguage"
      :placeholders="placeholders"
      @close="editingTemplate = null"
      @saved="onTemplateSaved"
      @change-language="(code) => changeLanguage(code)"
    />
  </div>
</template>
