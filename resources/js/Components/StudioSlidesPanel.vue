<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { StudioSlide } from '../lib/studioSession'
import { csrfToken } from '../lib/webrtcSignaling'

const props = defineProps<{
  slug: string
  presenterKey: string
  slides: StudioSlide[]
  currentSlideId: number | null
  maxSlideKb: number
}>()

const uploading = ref(false)
const uploadError = ref('')

async function uploadSlide(ev: Event): Promise<void> {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploadError.value = ''
  if (file.size > props.maxSlideKb * 1024) {
    uploadError.value = `File is larger than ${Math.round(props.maxSlideKb / 1024)} MB.`
    input.value = ''
    return
  }
  uploading.value = true
  try {
    const form = new FormData()
    form.append('slides', file)
    const res = await fetch(`/studio/${props.slug}/${props.presenterKey}/slides`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: form,
    })
    if (!res.ok) {
      const data = await res.json().catch(() => null)
      uploadError.value = data?.message || 'Upload failed.'
      return
    }
    router.reload({ only: ['slides', 'currentSlideId'] })
  } finally {
    uploading.value = false
    input.value = ''
  }
}

function setCurrent(slideId: number | null): void {
  router.post(
    `/studio/${props.slug}/${props.presenterKey}/live`,
    { slide_id: slideId },
    { preserveScroll: true },
  )
}
</script>

<template>
  <div class="flex flex-col h-full min-h-0 p-3 space-y-3 overflow-y-auto bg-[#FAF8F2] text-[#202423]">
    <div class="gr-card p-3 space-y-2">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-[#202423]">Upload PDF or PPTX</span>
        <span class="text-[11px] text-[#5C6260]">Max {{ Math.round(maxSlideKb / 1024) }} MB</span>
      </div>
      <label
        class="block text-center rounded-xl border border-dashed border-[#006849]/40 bg-[#F2EFE6] hover:border-[#006849] hover:bg-[#C9F284]/35 px-3 py-2.5 text-xs font-semibold text-[#006849] cursor-pointer transition"
      >
        {{ uploading ? 'Uploading...' : 'Choose .pdf or .pptx file' }}
        <input
          type="file"
          accept=".pdf,.pptx,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation"
          class="hidden"
          :disabled="uploading"
          @change="uploadSlide"
        />
      </label>
      <p v-if="uploadError" class="text-xs text-[#C43D3D] font-medium">{{ uploadError }}</p>
    </div>

    <div class="space-y-2">
      <div class="flex items-center justify-between px-0.5">
        <h3 class="text-[11px] font-bold uppercase tracking-wider text-[#5C6260]">Decks</h3>
        <button
          v-if="currentSlideId !== null"
          type="button"
          @click="setCurrent(null)"
          class="text-[11px] font-semibold text-[#006849] hover:underline"
        >
          Clear stage
        </button>
      </div>
      <p v-if="!slides.length" class="text-xs text-[#5C6260] px-0.5">
        Upload a PDF or PowerPoint deck to display on stage.
      </p>
      <div
        v-for="s in slides"
        :key="s.id"
        class="rounded-xl border p-2.5 text-xs space-y-2 transition"
        :class="
          s.id === currentSlideId
            ? 'border-[#006849] bg-[#C9F284]/25 shadow-xs'
            : 'border-[#E5E0D5] bg-white'
        "
      >
        <div class="flex items-center justify-between gap-2">
          <span class="font-semibold text-[#202423] truncate" :title="s.name">{{ s.name }}</span>
          <span
            v-if="s.id === currentSlideId"
            class="gr-badge shrink-0 text-[10px] py-0.5 px-1.5"
          >
            ON STAGE
          </span>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="setCurrent(s.id)"
            class="gr-btn px-2.5 py-1 text-[11px]"
            :class="s.id === currentSlideId ? 'gr-btn-primary' : ''"
          >
            {{ s.id === currentSlideId ? 'Active' : 'Put on stage' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
