<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import EventCoverCard from '@/Components/EventCoverCard.vue'

export interface EditableEvent {
  id: string
  slug: string
  title: string
  description: string
  cover_theme: string
  cover_badge: string
  cover_image_url: string | null
  has_cover_image: boolean
  scheduled_at: string | null
}

const props = defineProps<{
  event: EditableEvent
  coverThemes: string[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved'): void
}>()

const THEME_LABELS: Record<string, { label: string; swatch: string }> = {
  pistachio: { label: 'Pistachio', swatch: '#C9F284' },
  lilac: { label: 'Soft Lilac', swatch: '#DCD3F7' },
  emerald: { label: 'Deep Emerald', swatch: '#006849' },
  charcoal: { label: 'Charcoal', swatch: '#202423' },
  paper: { label: 'Warm Paper', swatch: '#FAF8F2' },
}

function toDatetimeLocal(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const title = ref(props.event.title)
const description = ref(props.event.description || '')
const scheduledAt = ref(toDatetimeLocal(props.event.scheduled_at))
const coverTheme = ref(props.event.cover_theme || 'pistachio')
const coverBadge = ref(props.event.cover_badge || '')
const selectedFile = ref<File | null>(null)
const previewObjectUrl = ref<string | null>(null)
const removeCoverImage = ref(false)
const saving = ref(false)
const errorMessage = ref('')

watch(
  () => props.event,
  (ev) => {
    title.value = ev.title
    description.value = ev.description || ''
    scheduledAt.value = toDatetimeLocal(ev.scheduled_at)
    coverTheme.value = ev.cover_theme || 'pistachio'
    coverBadge.value = ev.cover_badge || ''
    selectedFile.value = null
    previewObjectUrl.value = null
    removeCoverImage.value = false
    errorMessage.value = ''
  }
)

const effectiveCoverImage = computed(() => {
  if (previewObjectUrl.value) return previewObjectUrl.value
  if (removeCoverImage.value) return null
  return props.event.cover_image_url
})

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] || null
  if (!file) return
  if (previewObjectUrl.value) {
    URL.revokeObjectURL(previewObjectUrl.value)
  }
  selectedFile.value = file
  previewObjectUrl.value = URL.createObjectURL(file)
  removeCoverImage.value = false
}

function clearCoverImage() {
  if (previewObjectUrl.value) {
    URL.revokeObjectURL(previewObjectUrl.value)
    previewObjectUrl.value = null
  }
  selectedFile.value = null
  removeCoverImage.value = true
}

function csrfToken(): string {
  const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
  if (meta?.content) return meta.content
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function submit() {
  if (!title.value.trim()) {
    errorMessage.value = 'Title is required.'
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const fd = new FormData()
    fd.append('title', title.value.trim())
    fd.append('description', description.value.trim())
    if (scheduledAt.value) {
      const iso = new Date(scheduledAt.value).toISOString()
      fd.append('scheduled_at', iso)
    } else {
      fd.append('scheduled_at', '')
    }
    fd.append('cover_theme', coverTheme.value)
    fd.append('cover_badge', coverBadge.value.trim())
    if (selectedFile.value) {
      fd.append('cover_image', selectedFile.value)
    } else if (removeCoverImage.value) {
      fd.append('remove_cover_image', '1')
    }

    const res = await fetch(`/events/${encodeURIComponent(props.event.slug)}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-XSRF-TOKEN': csrfToken(),
      },
      body: fd,
    })

    if (!res.ok) {
      const data = await res.json().catch(() => ({}))
      const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null
      errorMessage.value = String(firstErr || data?.message || 'Could not save event details.')
      return
    }
    emit('saved')
  } catch {
    errorMessage.value = 'Network error while saving event details.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-[2px]"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-[#FAF8F2] border border-[#E5E0D5] shadow-2xl">
      <div class="flex items-center justify-between border-b border-[#E5E0D5] px-6 py-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-widest text-[#006849]">Registration page preview</p>
          <h2 class="font-serif text-2xl font-normal text-[#202423]">Edit event details</h2>
        </div>
        <button
          type="button"
          class="rounded-lg border border-[#E5E0D5] px-3 py-1.5 text-xs font-medium text-[#202423]/70 hover:bg-[#F3EFE6]"
          @click="emit('close')"
        >
          Close
        </button>
      </div>

      <form class="grid gap-6 p-6 md:grid-cols-12" @submit.prevent="submit">
        <div class="space-y-4 md:col-span-7">
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-1.5">
              Webinar title
            </label>
            <input
              v-model="title"
              type="text"
              maxlength="120"
              required
              class="w-full rounded-xl border border-[#D9D3C7] bg-white px-3.5 py-2.5 text-sm text-[#202423] focus:border-[#006849] focus:outline-none"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-1.5">
              Registration page description
            </label>
            <textarea
              v-model="description"
              rows="4"
              maxlength="2000"
              placeholder="Tell prospective attendees what they will learn, who it is for, and what to expect."
              class="w-full rounded-xl border border-[#D9D3C7] bg-white px-3.5 py-2.5 text-sm text-[#202423] focus:border-[#006849] focus:outline-none"
            />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-1.5">
                Scheduled date &amp; time
              </label>
              <input
                v-model="scheduledAt"
                type="datetime-local"
                class="w-full rounded-xl border border-[#D9D3C7] bg-white px-3 py-2 text-sm text-[#202423] focus:border-[#006849] focus:outline-none"
              />
            </div>

            <div>
              <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-1.5">
                Cover badge label
              </label>
              <input
                v-model="coverBadge"
                type="text"
                maxlength="60"
                placeholder="e.g. Live workshop"
                class="w-full rounded-xl border border-[#D9D3C7] bg-white px-3 py-2 text-sm text-[#202423] focus:border-[#006849] focus:outline-none"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-2">
              Cover card theme
            </label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="t in coverThemes"
                :key="t"
                type="button"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition"
                :class="
                  coverTheme === t
                    ? 'border-[#006849] bg-[#006849]/10 text-[#006849]'
                    : 'border-[#D9D3C7] bg-white text-[#202423]/80 hover:border-[#202423]/40'
                "
                @click="coverTheme = t"
              >
                <span
                  class="h-3.5 w-3.5 rounded-full border border-black/15"
                  :style="{ backgroundColor: THEME_LABELS[t]?.swatch || '#C9F284' }"
                />
                {{ THEME_LABELS[t]?.label || t }}
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-[#202423]/70 mb-1.5">
              Custom cover image (optional, PNG/JPG/WebP up to 4 MB)
            </label>
            <div class="flex flex-wrap items-center gap-3">
              <label class="cursor-pointer rounded-xl border border-[#D9D3C7] bg-white px-3.5 py-2 text-xs font-medium text-[#202423] hover:bg-[#F3EFE6]">
                Upload image
                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onFileChange" />
              </label>
              <button
                v-if="effectiveCoverImage"
                type="button"
                class="text-xs font-medium text-[#C43D3D] hover:underline"
                @click="clearCoverImage"
              >
                Remove image
              </button>
              <span v-if="selectedFile" class="text-xs text-[#202423]/60 truncate max-w-[200px]">
                {{ selectedFile.name }}
              </span>
            </div>
          </div>

          <p v-if="errorMessage" class="rounded-lg bg-[#C43D3D]/10 px-3 py-2 text-xs font-medium text-[#C43D3D]">
            {{ errorMessage }}
          </p>

          <div class="flex items-center justify-end gap-3 pt-2">
            <button
              type="button"
              class="rounded-xl border border-[#D9D3C7] px-4 py-2 text-sm font-medium text-[#202423]/75 hover:bg-[#F3EFE6]"
              @click="emit('close')"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="saving"
              class="rounded-xl bg-[#006849] px-5 py-2 text-sm font-medium text-white hover:bg-[#00553b] disabled:opacity-60"
            >
              {{ saving ? 'Saving...' : 'Save changes' }}
            </button>
          </div>
        </div>

        <div class="md:col-span-5">
          <p class="text-xs font-semibold uppercase tracking-wider text-[#202423]/60 mb-2">
            Live registration card preview
          </p>
          <EventCoverCard
            :title="title || 'Untitled webinar'"
            :description="description"
            :theme="coverTheme"
            :badge="coverBadge"
            :image-url="effectiveCoverImage"
            :scheduled-at="scheduledAt ? new Date(scheduledAt).toISOString() : null"
            size="md"
          />
          <p class="mt-3 text-xs leading-relaxed text-[#202423]/60">
            This cover card and description appear directly on the public registration page when guests sign up.
          </p>
        </div>
      </form>
    </div>
  </div>
</template>
