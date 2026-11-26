<script setup lang="ts">
import { computed } from 'vue'

export type CoverTheme = 'pistachio' | 'lilac' | 'emerald' | 'charcoal'

const props = withDefaults(defineProps<{
  title: string
  theme?: string | null
  badge?: string | null
  imageUrl?: string | null
  statusLabel?: string | null
  isLive?: boolean
  scheduledLabel?: string | null
  compact?: boolean
}>(), {
  theme: 'pistachio',
  badge: null,
  imageUrl: null,
  statusLabel: null,
  isLive: false,
  scheduledLabel: null,
  compact: false,
})

const themeClasses = computed(() => {
  switch (props.theme) {
    case 'lilac':
      return {
        bg: 'bg-[#DCD3F7] text-[#202423] border-[#C6BCE8]',
        eyebrow: 'bg-[#202423]/10 text-[#202423]',
        accent: 'bg-[#006849]/15',
        subtext: 'text-[#202423]/75',
      }
    case 'emerald':
      return {
        bg: 'bg-[#006849] text-[#FAF8F2] border-[#00543B]',
        eyebrow: 'bg-[#C9F284] text-[#006849]',
        accent: 'bg-[#C9F284]/15',
        subtext: 'text-[#FAF8F2]/80',
      }
    case 'charcoal':
      return {
        bg: 'bg-[#202423] text-[#FAF8F2] border-[#2E3331]',
        eyebrow: 'bg-[#DCD3F7] text-[#202423]',
        accent: 'bg-[#C9F284]/12',
        subtext: 'text-[#FAF8F2]/75',
      }
    case 'pistachio':
    default:
      return {
        bg: 'bg-[#C9F284] text-[#202423] border-[#B5DF6C]',
        eyebrow: 'bg-[#006849] text-[#FAF8F2]',
        accent: 'bg-[#006849]/10',
        subtext: 'text-[#202423]/75',
      }
  }
})
</script>

<template>
  <div
    class="relative overflow-hidden rounded-2xl border transition"
    :class="[
      themeClasses.bg,
      compact ? 'min-h-[140px] p-4' : 'min-h-[210px] p-6 sm:p-7',
    ]"
  >
    <img
      v-if="imageUrl"
      :src="imageUrl"
      :alt="title"
      class="absolute inset-0 h-full w-full object-cover"
    />
    <div
      v-if="imageUrl"
      class="absolute inset-0 bg-gradient-to-t from-[#202423]/85 via-[#202423]/40 to-[#202423]/20"
    />
    <template v-else>
      <div
        class="pointer-events-none absolute -right-10 -top-10 h-36 w-36 rounded-full"
        :class="themeClasses.accent"
      />
      <div
        class="pointer-events-none absolute -bottom-12 left-1/3 h-28 w-28 rounded-full"
        :class="themeClasses.accent"
      />
    </template>

    <div class="relative z-10 flex h-full flex-col justify-between gap-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span
          v-if="badge"
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold tracking-wide"
          :class="imageUrl ? 'bg-[#C9F284] text-[#006849]' : themeClasses.eyebrow"
        >
          {{ badge }}
        </span>
        <span
          v-else
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold tracking-wide"
          :class="imageUrl ? 'bg-[#FAF8F2]/90 text-[#202423]' : themeClasses.eyebrow"
        >
          Greenroom Webinar
        </span>

        <span
          v-if="statusLabel"
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold"
          :class="isLive
            ? 'bg-[#C43D3D] text-white'
            : imageUrl
              ? 'bg-[#202423]/75 text-[#FAF8F2]'
              : 'bg-black/10 text-current'"
        >
          <span v-if="isLive" class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />
          {{ statusLabel }}
        </span>
      </div>

      <div class="space-y-1.5">
        <h3
          class="font-bold tracking-tight leading-tight"
          :class="[
            compact ? 'text-lg line-clamp-2' : 'text-2xl sm:text-3xl',
            imageUrl ? 'text-[#FAF8F2]' : '',
          ]"
        >
          {{ title || 'Untitled webinar' }}
        </h3>
        <p
          v-if="scheduledLabel"
          class="text-xs font-medium"
          :class="imageUrl ? 'text-[#FAF8F2]/85' : themeClasses.subtext"
        >
          {{ scheduledLabel }}
        </p>
      </div>
    </div>
  </div>
</template>
