<?php

namespace App\Services;

use App\Models\Recording;
use App\Models\Room;
use App\Models\SaasSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaasService
{
    public function studio(User $user): ?SaasSubscription
    {
        return $user->saasSubscriptions()->whereIn('status', ['active', 'trialing'])
            ->latest('id')->first();
    }

    public function monthlyUsage(User $user): int
    {
        $month = now()->startOfMonth();

        return Room::where('user_id', $user->id)->where('saas_source', 'studio')
            ->where('created_at', '>=', $month)->where('created_at', '<', $month->copy()->addMonth())
            ->count();
    }

    public function createRoom(?User $user, array $attributes): ?Room
    {
        if (! config('saas.enabled')) {
            return Room::create($attributes);
        }

        if ($user === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $attributes) {
            // Serialize monthly quota and credit consumption for this owner.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $studio = $this->studio($user);
            if ($studio && $this->monthlyUsage($user) < config('saas.studio_rooms_per_month')) {
                $room = new Room($attributes);
                $room->forceFill(['saas_source' => 'studio'])->save();

                return $room;
            }

            // A pass is independent of Studio. An extra room purchased during
            // Studio remains usable even if the subscription later ends.
            $credit = $user->saasCredits()->whereNull('consumed_at')
                ->orderByRaw("case when type = 'extra_event' then 0 else 1 end")
                ->orderBy('id')->first();

            if ($credit === null) {
                return null;
            }

            $room = new Room($attributes);
            $room->forceFill(['saas_source' => $credit->type])->save();
            $credit->update(['room_id' => $room->id, 'consumed_at' => now()]);

            return $room;
        }, 3);
    }

    public function canDownload(User $user, Recording $recording, ?array $studioRecordingIds = null): bool
    {
        if (! config('saas.enabled')) {
            return true;
        }

        if ($recording->room->saas_source === 'pass') {
            return $recording->created_at->gt(now()->subDays(config('saas.pass_download_days')));
        }

        return in_array($recording->id, $studioRecordingIds ?? $this->studioRecordingIds($user), true);
    }

    public function studioRecordingIds(User $user): array
    {
        if (! config('saas.enabled') || ! $this->studio($user)) {
            return [];
        }

        return Recording::whereHas('room', fn ($query) => $query
            ->where('user_id', $user->id)
            ->where(fn ($rooms) => $rooms->whereNull('saas_source')
                ->orWhereIn('saas_source', ['studio', 'extra_event'])))
            ->latest()->orderByDesc('id')->limit(config('saas.studio_recordings'))->pluck('id')->all();
    }
}
