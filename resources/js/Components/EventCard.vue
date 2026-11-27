<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import EventCoverCard from '@/Components/EventCoverCard.vue';

const props = defineProps({
    event: { type: Object, required: true },
});

const emit = defineEmits(['edit']);

const showAttendees = ref(false);
const showRecordings = ref(false);
const copiedReg = ref(false);

const statusBadge = computed(() => {
    if (props.event.is_live) {
        return { label: 'Live now', cls: 'bg-[#C43D3D] text-white' };
    }
    if (props.event.has_ended) {
        return { label: 'Past event', cls: 'bg-[#202423]/10 text-[#202423]/75' };
    }
    return { label: 'Upcoming', cls: 'bg-[#C9F284] text-[#202423]' };
});

function formatDate(iso) {
    if (!iso) return 'Date not scheduled';
    try {
        return new Date(iso).toLocaleString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function formatDuration(sec) {
    if (!sec || sec <= 0) return '—';
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function copyRegistrationLink() {
    const url = window.location.origin + props.event.registration_url;
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(url).catch(() => {});
    }
    copiedReg.value = true;
    setTimeout(() => { copiedReg.value = false; }, 1800);
}
</script>

<template>
    <article class="rounded-2xl border border-[#202423]/12 bg-white shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-[240px_1fr] gap-5 p-5">
            <!-- Cover Card Preview -->
            <div class="flex flex-col gap-2">
                <EventCoverCard
                    :title="event.title"
                    :description="event.description"
                    :theme="event.cover_theme"
                    :badge="event.cover_badge"
                    :image-url="event.cover_image_url"
                    :scheduled-at="event.scheduled_at"
                    compact
                />
                <button
                    type="button"
                    class="w-full rounded-lg border border-[#202423]/15 bg-[#FAF8F2] px-3 py-1.5 text-xs font-semibold text-[#202423] hover:bg-[#C9F284]/40 transition"
                    @click="emit('edit', event)"
                >
                    Edit cover &amp; details
                </button>
            </div>

            <!-- Event Metadata & Actions -->
            <div class="flex flex-col justify-between gap-4 min-w-0">
                <div>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="statusBadge.cls"
                        >
                            {{ statusBadge.label }}
                        </span>
                        <span class="text-xs text-[#202423]/60 font-medium">
                            {{ formatDate(event.scheduled_at || event.created_at) }}
                        </span>
                        <span class="text-xs text-[#202423]/45">•</span>
                        <span class="text-xs text-[#202423]/60 uppercase tracking-wide">
                            Emails: {{ event.email_language || 'en' }}
                        </span>
                    </div>

                    <h3 class="font-display text-xl font-bold text-[#202423] leading-snug truncate">
                        {{ event.title }}
                    </h3>
                    <p
                        v-if="event.description"
                        class="mt-1.5 text-sm text-[#202423]/75 line-clamp-2"
                    >
                        {{ event.description }}
                    </p>
                    <p v-else class="mt-1.5 text-sm italic text-[#202423]/45">
                        No description yet — click “Edit cover &amp; details” to add one for your registration page.
                    </p>

                    <!-- Quick Stats Row -->
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full bg-[#FAF8F2] border border-[#202423]/10 px-2.5 py-1 font-medium text-[#202423]">
                            {{ event.attendees_count }} registered
                        </span>
                        <span
                            v-if="event.returning_count > 0"
                            class="rounded-full bg-[#DCD3F7]/70 px-2.5 py-1 font-semibold text-[#202423]"
                        >
                            {{ event.returning_count }} returning
                        </span>
                        <span
                            v-if="event.first_time_count > 0"
                            class="rounded-full bg-[#C9F284]/60 px-2.5 py-1 font-semibold text-[#202423]"
                        >
                            {{ event.first_time_count }} first-time
                        </span>
                        <span
                            v-if="event.recordings?.length"
                            class="rounded-full bg-[#006849]/10 px-2.5 py-1 font-semibold text-[#006849]"
                        >
                            {{ event.recordings.length }} {{ event.recordings.length === 1 ? 'video' : 'videos' }}
                        </span>
                    </div>
                </div>

                <!-- Action Links Bar -->
                <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-[#202423]/10">
                    <Link
                        :href="event.studio_url"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-[#006849] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[#00553b] transition"
                    >
                        Backstage studio
                    </Link>
                    <Link
                        :href="event.emails_url"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-[#202423]/15 bg-[#FAF8F2] px-3 py-2 text-xs font-semibold text-[#202423] hover:bg-[#C9F284]/50 transition"
                    >
                        Email settings
                    </Link>
                    <a
                        :href="event.registration_url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-[#202423]/15 bg-white px-3 py-2 text-xs font-semibold text-[#202423] hover:bg-[#FAF8F2] transition"
                    >
                        Registration page
                    </a>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-[#202423]/12 px-2.5 py-2 text-xs font-medium text-[#202423]/75 hover:bg-[#FAF8F2]"
                        @click="copyRegistrationLink"
                    >
                        {{ copiedReg ? 'Copied link' : 'Copy invite' }}
                    </button>

                    <div class="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1.5 text-xs font-semibold transition"
                            :class="showAttendees ? 'bg-[#202423] text-white' : 'bg-[#FAF8F2] text-[#202423] hover:bg-[#202423]/10'"
                            @click="showAttendees = !showAttendees"
                        >
                            Attendees ({{ event.attendees_count }})
                        </button>
                        <button
                            v-if="event.recordings?.length"
                            type="button"
                            class="rounded-lg px-2.5 py-1.5 text-xs font-semibold transition"
                            :class="showRecordings ? 'bg-[#006849] text-white' : 'bg-[#006849]/10 text-[#006849] hover:bg-[#006849]/20'"
                            @click="showRecordings = !showRecordings"
                        >
                            Videos ({{ event.recordings.length }})
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Expandable Attendee List -->
        <div
            v-if="showAttendees"
            class="border-t border-[#202423]/10 bg-[#FAF8F2]/80 px-5 py-4"
        >
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-[#202423]/70">
                    Registered attendees ({{ event.attendees_count }})
                </h4>
                <span class="text-xs text-[#202423]/55">
                    Returning = registered for more than one of your webinars
                </span>
            </div>

            <div v-if="!event.attendees?.length" class="text-sm text-[#202423]/55 py-2">
                No attendees registered yet. Share the registration page link to collect sign-ups.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-[#202423]/10 text-[#202423]/60">
                            <th class="py-2 pr-3 font-semibold">Attendee</th>
                            <th class="py-2 pr-3 font-semibold">Email</th>
                            <th class="py-2 pr-3 font-semibold">Status</th>
                            <th class="py-2 font-semibold">Registered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#202423]/8">
                        <tr v-for="att in event.attendees" :key="att.id">
                            <td class="py-2 pr-3 font-medium text-[#202423]">{{ att.name }}</td>
                            <td class="py-2 pr-3 text-[#202423]/75">{{ att.email }}</td>
                            <td class="py-2 pr-3">
                                <span
                                    v-if="att.is_returning"
                                    class="inline-flex items-center rounded-full bg-[#DCD3F7] px-2 py-0.5 text-[11px] font-semibold text-[#202423]"
                                >
                                    Returning ({{ att.past_events_count }} prior)
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-[#C9F284] px-2 py-0.5 text-[11px] font-semibold text-[#202423]"
                                >
                                    First-time
                                </span>
                            </td>
                            <td class="py-2 text-[#202423]/60">
                                {{ formatDate(att.registered_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Expandable Past Webinar Videos / Recordings -->
        <div
            v-if="showRecordings && event.recordings?.length"
            class="border-t border-[#202423]/10 bg-white px-5 py-4"
        >
            <h4 class="text-xs font-bold uppercase tracking-wider text-[#202423]/70 mb-3">
                Webinar videos &amp; recordings
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div
                    v-for="rec in event.recordings"
                    :key="rec.id"
                    class="rounded-xl border border-[#202423]/12 bg-[#FAF8F2] p-3 flex flex-col gap-2"
                >
                    <video
                        v-if="rec.media_url"
                        :src="rec.media_url"
                        controls
                        preload="metadata"
                        class="w-full rounded-lg bg-[#202423] aspect-video"
                    />
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <div>
                            <div class="font-semibold text-[#202423]">
                                {{ rec.download_limited ? 'Outside your download allowance' : formatDuration(rec.duration_seconds) }}
                                <span class="text-[#202423]/55 font-normal">• {{ rec.status }}</span>
                            </div>
                            <div class="text-[11px] text-[#202423]/55">
                                {{ formatDate(rec.created_at) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="rec.replay_url"
                                :href="rec.replay_url"
                                class="rounded-md bg-[#006849] px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-[#00553b]"
                            >
                                Replay page
                            </Link>
                            <a
                                v-if="rec.media_url"
                                :href="rec.media_url"
                                download
                                class="rounded-md border border-[#202423]/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-[#202423] hover:bg-[#FAF8F2]"
                            >
                                Download
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </article>
</template>
