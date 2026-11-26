<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import TipTapEmailEditor, { type EmailPlaceholder } from './TipTapEmailEditor.vue'

export interface TemplateRow {
  key: string
  group: string
  label: string
  audience: string
  timing: string
  enabled: boolean
  attach_recording: boolean
  subject: string
  body_html: string
  default_subject?: string
  default_body_html?: string
}

export type EmailTemplateItem = TemplateRow

interface LanguageOption {
  code: string
  name: string
  native_name: string
  direction: 'ltr' | 'rtl'
}

const props = defineProps<{
  template: TemplateRow | null
  roomSlug: string
  presenterKey: string
  hasRecording: boolean
  emailLanguage: string
  languageLabel?: string
  languages?: LanguageOption[]
  savingLanguage?: boolean
  placeholders: EmailPlaceholder[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved', updated: TemplateRow, templates?: TemplateRow[]): void
  (e: 'changeLanguage', code: string): void
}>()

const subject = ref('')
const bodyHtml = ref('')
const enabled = ref(true)
const attachRecording = ref(false)
const saving = ref(false)
const resetting = ref(false)
const errorMsg = ref<string | null>(null)
const statusMsg = ref<string | null>(null)
const previewOpen = ref(false)
const previewData = ref<{ subject: string; body_html: string } | null>(null)

const resolvedLanguageLabel = computed(() => {
  const label = props.languageLabel?.trim()
  if (label) return label
  const code = props.emailLanguage?.trim()
  return code ? code.toUpperCase() : 'default'
})

watch(
  () => [
    props.template?.key,
    props.template?.subject,
    props.template?.body_html,
    props.template?.enabled,
    props.template?.attach_recording,
    props.emailLanguage,
  ] as const,
  ([key, nextSubject, nextBody, nextEnabled, nextAttach, nextLang], prev) => {
    if (!props.template || !key) return
    const prevKey = prev?.[0]
    const prevLang = prev?.[5]
    subject.value = nextSubject ?? props.template.subject
    bodyHtml.value = nextBody ?? props.template.body_html
    enabled.value = nextEnabled ?? props.template.enabled
    attachRecording.value = nextAttach ?? props.template.attach_recording
    errorMsg.value = null
    previewOpen.value = false
    previewData.value = null
    if (prevKey === key && prevLang && prevLang !== nextLang) {
      statusMsg.value = `Campaign language updated to ${resolvedLanguageLabel.value} and template reloaded.`
    } else if (prevKey !== key) {
      statusMsg.value = null
    }
  },
  { immediate: true },
)

function csrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function saveTemplate() {
  if (!props.template) return
  saving.value = true
  errorMsg.value = null
  statusMsg.value = null
  try {
    const res = await fetch(
      `/studio/${props.roomSlug}/${props.presenterKey}/emails/templates/${props.template.key}`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
          subject: subject.value,
          body_html: bodyHtml.value,
          enabled: enabled.value,
          attach_recording: attachRecording.value,
        }),
      },
    )
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(data.message || 'Could not save email template.')
    }
    const templates = Array.isArray(data.templates) ? (data.templates as TemplateRow[]) : undefined
    const updated =
      (data.template as TemplateRow | undefined) ??
      templates?.find((t) => t.key === props.template?.key)
    if (!updated) {
      throw new Error('Could not read updated template from response.')
    }
    emit('saved', updated, templates)
    emit('close')
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'Save failed.'
  } finally {
    saving.value = false
  }
}

async function resetTemplate() {
  if (!props.template) return
  resetting.value = true
  errorMsg.value = null
  statusMsg.value = null
  try {
    const res = await fetch(
      `/studio/${props.roomSlug}/${props.presenterKey}/emails/templates/${props.template.key}/reset`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ language: props.emailLanguage }),
      },
    )
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(data.message || 'Could not reset template.')
    }
    const templates = Array.isArray(data.templates) ? (data.templates as TemplateRow[]) : undefined
    const tpl =
      (data.template as TemplateRow | undefined) ??
      templates?.find((t) => t.key === props.template?.key)
    if (!tpl) {
      throw new Error('Could not read reset template from response.')
    }
    subject.value = tpl.subject
    bodyHtml.value = tpl.body_html
    enabled.value = tpl.enabled
    attachRecording.value = tpl.attach_recording
    previewOpen.value = false
    previewData.value = null
    statusMsg.value = `Reset to ${resolvedLanguageLabel.value} default.`
    emit('saved', tpl, templates)
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'Reset failed.'
  } finally {
    resetting.value = false
  }
}

async function loadPreview() {
  if (!props.template) return
  errorMsg.value = null
  try {
    const res = await fetch(
      `/studio/${props.roomSlug}/${props.presenterKey}/emails/templates/${props.template.key}/preview`,
      { headers: { Accept: 'application/json' } },
    )
    if (!res.ok) {
      throw new Error('Could not load preview.')
    }
    const data = await res.json()
    const preview = data.preview ?? data
    previewData.value = { subject: preview.subject, body_html: preview.body_html }
    previewOpen.value = true
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'Could not load preview.'
  }
}
</script>

<template>
  <div
    v-if="template"
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#202423]/65 p-4 backdrop-blur-sm"
    @click.self="emit('close')"
  >
    <div class="gr-card w-full max-w-3xl max-h-[92vh] overflow-y-auto shadow-2xl">
      <header class="flex items-start justify-between gap-4 border-b border-[#E5E0D5] bg-[#FAF8F2] px-6 py-4">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="gr-eyebrow">{{ template.group }}</span>
            <select
              v-if="languages && languages.length > 0"
              id="modal-email-language-select"
              :value="emailLanguage"
              :disabled="savingLanguage"
              aria-label="Campaign email language"
              class="rounded-full border border-[#006849]/30 bg-[#C9F284]/40 px-2.5 py-0.5 text-xs font-semibold text-[#006849] cursor-pointer focus:border-[#006849] focus:outline-none disabled:opacity-50"
              @change="emit('changeLanguage', ($event.target as HTMLSelectElement).value)"
            >
              <option
                v-for="lang in languages"
                :key="lang.code"
                :value="lang.code"
              >
                {{ lang.native_name }} ({{ lang.name }})
              </option>
            </select>
            <span v-else class="gr-badge">{{ resolvedLanguageLabel }}</span>
          </div>
          <h2 class="mt-1 text-xl font-bold text-[#202423]">{{ template.label }}</h2>
          <p class="gr-muted mt-0.5 text-xs">
            {{ template.audience }} &middot; {{ template.timing }}
          </p>
        </div>
        <button
          type="button"
          class="gr-btn px-3 py-1.5 text-xs"
          @click="emit('close')"
        >
          Close
        </button>
      </header>

      <div class="space-y-4 px-6 py-5 bg-white">
        <div class="gr-card-soft flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-[#202423]">
            <input v-model="enabled" type="checkbox" class="h-4 w-4 accent-[#006849]" />
            <span>Automatically send this email</span>
          </label>

          <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-[#202423]">
            <input v-model="attachRecording" type="checkbox" class="h-4 w-4 accent-[#006849]" />
            <span>Attach live webinar recording</span>
            <span
              :class="hasRecording ? 'gr-badge' : 'gr-badge-lilac'"
            >
              {{ hasRecording ? 'Recording ready' : 'Attached once recorded' }}
            </span>
          </label>
        </div>

        <div>
          <label class="gr-label" for="tpl-subject">Email subject</label>
          <input
            id="tpl-subject"
            v-model="subject"
            type="text"
            maxlength="200"
            class="gr-input"
            placeholder="Subject line…"
          />
        </div>

        <div>
          <label class="gr-label">Email body (TipTap rich-text editor)</label>
          <TipTapEmailEditor v-model="bodyHtml" :placeholders="placeholders" />
        </div>

        <div
          v-if="previewOpen && previewData"
          class="gr-card-soft space-y-2.5 p-4"
        >
          <div class="flex items-center justify-between">
            <span class="gr-eyebrow">Rendered preview (saved version)</span>
            <button type="button" class="text-xs font-semibold text-[#006849] hover:underline" @click="previewOpen = false">
              Hide
            </button>
          </div>
          <p class="text-sm font-semibold text-[#202423]">Subject: {{ previewData.subject }}</p>
          <div class="prose prose-sm max-w-none rounded-xl border border-[#E5E0D5] bg-white p-4 text-[#202423]" v-html="previewData.body_html" />
        </div>

        <p v-if="statusMsg" class="rounded-xl border border-[#006849]/30 bg-[#C9F284]/35 px-3 py-2 text-xs font-medium text-[#006849]">
          {{ statusMsg }}
        </p>

        <p v-if="errorMsg" class="rounded-xl border border-[#C43D3D]/30 bg-[#C43D3D]/10 px-3 py-2 text-xs font-medium text-[#C43D3D]">
          {{ errorMsg }}
        </p>
      </div>

      <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-[#E5E0D5] bg-[#FAF8F2] px-6 py-4">
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="gr-btn text-xs"
            :disabled="resetting"
            @click="resetTemplate"
          >
            {{ resetting ? 'Resetting…' : `Reset to ${resolvedLanguageLabel} default` }}
          </button>
          <button
            type="button"
            class="gr-btn text-xs"
            @click="loadPreview"
          >
            Preview saved email
          </button>
        </div>

        <div class="flex items-center gap-2">
          <button type="button" class="gr-btn text-sm" @click="emit('close')">
            Cancel
          </button>
          <button
            type="button"
            class="gr-btn gr-btn-primary text-sm"
            :disabled="saving"
            @click="saveTemplate"
          >
            {{ saving ? 'Saving…' : 'Save template' }}
          </button>
        </div>
      </footer>
    </div>
  </div>
</template>
