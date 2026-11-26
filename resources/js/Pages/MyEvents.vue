<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GreenroomLogo from '@/Components/GreenroomLogo.vue';
import EventCard from '@/Components/EventCard.vue';
import EventEditModal from '@/Components/EventEditModal.vue';

const props = defineProps({
    upcoming: { type: Array, default: () => [] },
    past: { type: Array, default: () => [] },
    coverThemes: { type: Array, default: () => ['pistachio', 'lilac', 'emerald', 'charcoal', 'paper'] },
    stats: {
        type: Object,
        default: () => ({
            total_events: 0,
            upcoming_count: 0,
            past_count: 0,
            unique_attendees: 0,
            returning_attendees: 0,
            recordings_count: 0,
        }),
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);

const activeTab = ref(props.upcoming.length > 0 ? 'upcoming' : (props.past.length > 0 ? 'past' : 'upcoming'));
const editingEvent = ref(null);
const quickTitle = ref('');
const creating = ref(false);

const displayedEvents = computed(() => (activeTab.value === 'upcoming' ? props.upcoming : props.past));

function openEdit(ev) {
    editingEvent.value = ev;
}

function onEventSaved() {
    editingEvent.value = null;
    router.reload({ only: ['upcoming', 'past', 'stats'] });
}

function createQuickEvent() {
    if (!quickTitle.value.trim() || creating.value) return;
    creating.value = true;
    router.post('/rooms', { title: quickTitle.value.trim() }, {
        onFinish: () => {
            creating.value = false;
            quickTitle.value = '';
        },
    });
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <Head title="My events — Greenroom" />

    <div class="min-h-screen bg-[#FAF8F2] text-[#202423] font-sans">
        <!-- Header -->
        <header class="border-b border-[#202423]/10 bg-[#FAF8F2]/90 backdrop-blur sticky top-0 z-20">
            <div class="mx-auto max-w-6xl px-5 py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-6">
                    <Link href="/" class="hover:opacity-85 transition">
                        <GreenroomLogo />
                    </Link>
                    <nav class="hidden sm:flex items-center gap-4 text-sm font-medium">
                        <Link href="/" class="text-[#202423]/70 hover:text-[#006849] transition">
                            Home
                        </Link>
                        <Link href="/events" class="text-[#006849] font-semibold">
                            My events
                        </Link>
                    </nav>
                </div>

                <div class="flex items-center gap-3 text-sm">
                    <span v-if="user" class="hidden sm:inline text-[#202423]/70 font-medium">
                        {{ user.name }}
                    </span>
                    <button
                        v-if="user"
                        type="button"
                        class="rounded-lg border border-[#202423]/15 bg-white px-3 py-1.5 text-xs font-semibold text-[#202423] hover:bg-[#FAF8F2]"
                        @click="logout"
                    >
                        Log out
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-5 py-8">
            <!-- Hero & Quick Create -->
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-6 mb-8">
                <div>
                    <span class="inline-flex items-center rounded-full bg-[#C9F284] px-3 py-1 text-xs font-bold uppercase tracking-wider text-[#202423]">
                        Host hub
                    </span>
                    <h1 class="mt-3 font-display text-3xl sm:text-4xl font-bold tracking-tight text-[#202423]">
                        My events
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm sm:text-base text-[#202423]/70">
                        Manage your upcoming and past webinars, review registered attendees, customize your registration cover card, configure emails directly, and jump straight into the backstage studio.
                    </p>
                </div>

                <form
                    class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto lg:min-w-[380px]"
                    @submit.prevent="createQuickEvent"
                >
                    <input
                        v-model="quickTitle"
                        type="text"
                        placeholder="New webinar title..."
                        class="flex-1 rounded-xl border border-[#202423]/15 bg-white px-4 py-2.5 text-sm text-[#202423] placeholder:text-[#202423]/40 focus:border-[#006849] focus:outline-none"
                    />
                    <button
                        type="submit"
                        :disabled="creating || !quickTitle.trim()"
                        class="rounded-xl bg-[#006849] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#00553b] disabled:opacity-50 transition whitespace-nowrap"
                    >
                        {{ creating ? 'Creating...' : '+ New webinar' }}
                    </button>
                </form>
            </div>

            <!-- Summary Metrics -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
                <div class="rounded-2xl border border-[#202423]/10 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-[#202423]/55">Upcoming</div>
                    <div class="mt-1 font-display text-2xl font-bold text-[#202423]">{{ stats.upcoming_count }}</div>
                </div>
                <div class="rounded-2xl border border-[#202423]/10 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-[#202423]/55">Past webinars</div>
                    <div class="mt-1 font-display text-2xl font-bold text-[#202423]">{{ stats.past_count }}</div>
                </div>
                <div class="rounded-2xl border border-[#202423]/10 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-[#202423]/55">Unique attendees</div>
                    <div class="mt-1 font-display text-2xl font-bold text-[#006849]">
                        {{ stats.unique_attendees }}
                        <span v-if="stats.returning_attendees > 0" class="text-xs font-semibold text-[#202423]/65">
                            ({{ stats.returning_attendees }} returning)
                        </span>
                    </div>
                </div>
                <div class="rounded-2xl border border-[#202423]/10 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-[#202423]/55">Past videos</div>
                    <div class="mt-1 font-display text-2xl font-bold text-[#202423]">{{ stats.recordings_count }}</div>
                </div>
            </div>

            <!-- Tabs: Upcoming vs Past -->
            <div class="flex items-center gap-2 border-b border-[#202423]/12 mb-6">
                <button
                    type="button"
                    class="px-4 py-2.5 text-sm font-semibold border-b-2 transition"
                    :class="activeTab === 'upcoming'
                        ? 'border-[#006849] text-[#006849]'
                        : 'border-transparent text-[#202423]/60 hover:text-[#202423]'"
                    @click="activeTab = 'upcoming'"
                >
                    Upcoming webinars ({{ upcoming.length }})
                </button>
                <button
                    type="button"
                    class="px-4 py-2.5 text-sm font-semibold border-b-2 transition"
                    :class="activeTab === 'past'
                        ? 'border-[#006849] text-[#006849]'
                        : 'border-transparent text-[#202423]/60 hover:text-[#202423]'"
                    @click="activeTab = 'past'"
                >
                    Past webinars ({{ past.length }})
                </button>
            </div>

            <!-- Event List -->
            <div v-if="displayedEvents.length" class="space-y-5">
                <EventCard
                    v-for="ev in displayedEvents"
                    :key="ev.id"
                    :event="ev"
                    @edit="openEdit"
                />
            </div>

            <!-- Empty State -->
            <div
                v-else
                class="rounded-2xl border border-dashed border-[#202423]/20 bg-white/70 p-10 text-center"
            >
                <h2 class="font-display text-xl font-bold text-[#202423]">
                    {{ activeTab === 'upcoming' ? 'No upcoming webinars yet' : 'No past webinars yet' }}
                </h2>
                <p class="mt-2 text-sm text-[#202423]/65 max-w-md mx-auto">
                    {{
                        activeTab === 'upcoming'
                            ? 'Create a new webinar above to get your registration page, customizable cover card, automated emails, and backstage studio.'
                            : 'Completed broadcasts and their recorded videos will appear here automatically once a session ends.'
                    }}
                </p>
            </div>
        </main>

        <!-- Edit Event Cover & Details Modal -->
        <EventEditModal
            v-if="editingEvent"
            :event="editingEvent"
            :cover-themes="coverThemes"
            @close="editingEvent = null"
            @saved="onEventSaved"
        />
    </div>
</template>
