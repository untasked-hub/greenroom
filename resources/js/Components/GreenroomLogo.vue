<script setup lang="ts">
defineProps<{
  subtitle?: string
  compact?: boolean
  inverted?: boolean
}>()
</script>

<template>
  <div class="inline-flex items-center gap-2.5 select-none">
    <!-- Three concentric half-arch Greenroom mark -->
    <svg
      :class="compact ? 'h-5 w-7' : 'h-6 w-8'"
      viewBox="0 0 36 22"
      fill="none"
      aria-hidden="true"
      :style="{ color: inverted ? '#C9F284' : '#006849' }"
    >
      <path
        d="M3 20C3 11.716 9.716 5 18 5C26.284 5 33 11.716 33 20"
        stroke="currentColor"
        stroke-width="3"
        stroke-linecap="round"
      />
      <path
        d="M8.5 20C8.5 14.753 12.753 10.5 18 10.5C23.247 10.5 27.5 14.753 27.5 20"
        stroke="currentColor"
        stroke-width="3"
        stroke-linecap="round"
      />
      <path
        d="M14 20C14 17.791 15.791 16 18 16C20.209 16 22 17.791 22 20"
        stroke="currentColor"
        stroke-width="3"
        stroke-linecap="round"
      />
    </svg>
    <span class="flex items-baseline gap-2.5 leading-none">
      <span
        class="font-extrabold tracking-tight"
        :class="[
          compact ? 'text-lg' : 'text-xl',
          inverted ? 'text-[#FAF8F2]' : 'text-[#006849]',
        ]"
      >
        greenroom
      </span>
      <span
        v-if="subtitle"
        class="border-l pl-2.5 text-xs font-medium"
        :class="
          inverted
            ? 'border-white/20 text-[#FAF8F2]/75'
            : 'border-[#D8D2C5] text-[#5C6260]'
        "
      >
        {{ subtitle }}
      </span>
    </span>
  </div>
</template>
