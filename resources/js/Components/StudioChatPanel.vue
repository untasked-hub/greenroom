<script setup lang="ts">
import { ref } from 'vue'
import { csrfToken } from '../lib/webrtcSignaling'

export interface ChatMessage {
  id: number
  author_name: string
  is_presenter: boolean
  body: string
  hidden: boolean
  pinned: boolean
  created_at: string
}

const props = defineProps<{
  slug: string
  presenterKey: string
  channel: 'backstage' | 'room'
  messages: ChatMessage[]
  presenterName: string
}>()

const emit = defineEmits<{
  (e: 'refresh'): void
  (e: 'update:presenterName', v: string): void
}>()

const draft = ref('')
const sending = ref(false)

async function send(): Promise<void> {
  const body = draft.value.trim()
  if (!body || sending.value) return
  sending.value = true
  try {
    const res = await fetch(`/r/${props.slug}/chat/${props.channel}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: JSON.stringify({
        key: props.presenterKey,
        author: props.presenterName.trim() || 'Presenter',
        body,
      }),
    })
    if (res.ok) {
      draft.value = ''
      emit('refresh')
    }
  } finally {
    sending.value = false
  }
}

async function moderate(id: number, action: 'hide' | 'show' | 'pin' | 'unpin'): Promise<void> {
  await fetch(`/r/${props.slug}/moderate/${id}/${action}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify({ key: props.presenterKey }),
  })
  emit('refresh')
}
</script>

<template>
  <div class="flex flex-col h-full min-h-0 bg-[#FAF8F2] text-[#202423]">
    <div class="px-3 py-2 border-b border-[#E5E0D5] bg-white flex items-center gap-2">
      <label class="text-xs font-semibold text-[#5C6260] shrink-0">Speaker</label>
      <input
        :value="presenterName"
        @input="emit('update:presenterName', ($event.target as HTMLInputElement).value)"
        type="text"
        maxlength="60"
        placeholder="Presenter"
        class="gr-input py-1 px-2 text-xs"
      />
    </div>
    <div class="flex-1 overflow-y-auto p-3 space-y-2">
      <p v-if="!messages.length" class="text-xs text-[#5C6260] text-center py-6">
        {{ channel === 'backstage' ? 'Private chat for presenters only.' : 'No guest messages yet.' }}
      </p>
      <div
        v-for="m in messages"
        :key="m.id"
        class="rounded-xl border p-2.5 text-xs transition"
        :class="
          m.pinned
            ? 'border-[#006849]/30 bg-[#C9F284]/35'
            : m.hidden
              ? 'border-[#E5E0D5] bg-[#F2EFE6] opacity-60'
              : 'border-[#E5E0D5] bg-white'
        "
      >
        <div class="flex items-center justify-between gap-2 mb-1">
          <span class="font-semibold text-[#202423]">{{ m.author_name }}</span>
          <div v-if="channel === 'room'" class="flex items-center gap-2 text-[11px]">
            <button
              type="button"
              @click="moderate(m.id, m.pinned ? 'unpin' : 'pin')"
              class="font-semibold text-[#006849] hover:underline"
            >
              {{ m.pinned ? 'Unpin' : 'Pin' }}
            </button>
            <button
              type="button"
              @click="moderate(m.id, m.hidden ? 'show' : 'hide')"
              class="text-[#5C6260] hover:underline"
            >
              {{ m.hidden ? 'Restore' : 'Hide' }}
            </button>
          </div>
        </div>
        <p class="text-[#202423] whitespace-pre-wrap break-words">{{ m.body }}</p>
      </div>
    </div>
    <form @submit.prevent="send" class="p-2.5 border-t border-[#E5E0D5] bg-white flex gap-2">
      <input
        v-model="draft"
        type="text"
        maxlength="1000"
        :placeholder="channel === 'backstage' ? 'Backstage note...' : 'Reply to guests...'"
        class="gr-input flex-1 py-1.5 px-2.5 text-xs"
      />
      <button
        type="submit"
        :disabled="sending || !draft.trim()"
        class="gr-btn gr-btn-primary px-3 py-1.5 text-xs"
      >
        Send
      </button>
    </form>
  </div>
</template>
