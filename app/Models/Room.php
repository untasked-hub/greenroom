<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    public const COVER_THEMES = ['pistachio', 'emerald', 'lilac', 'charcoal', 'paper'];

    protected $fillable = [
        'slug',
        'title',
        'description',
        'cover_theme',
        'cover_badge',
        'cover_image_key',
        'cover_image_mime',
        'email_language',
        'presenter_key_hash',
        'presenter_key_encrypted',
        'current_slide_id',
        'current_slide_page',
        'stage_source',
        'speaker_layout',
        'user_id',
        'scheduled_at',
        'live_started_at',
        'live_ended_at',
        'reminder_dispatched_at',
        'followup_dispatched_at',
    ];

    protected $hidden = [
        'presenter_key_hash',
        'presenter_key_encrypted',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'live_started_at' => 'datetime',
        'live_ended_at' => 'datetime',
        'reminder_dispatched_at' => 'datetime',
        'followup_dispatched_at' => 'datetime',
        'current_slide_page' => 'integer',
    ];

    public function isLive(): bool
    {
        return $this->live_started_at !== null && $this->live_ended_at === null;
    }

    public function hasEnded(): bool
    {
        return $this->live_ended_at !== null;
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function latestRecording(): ?Recording
    {
        return $this->recordings()->orderByDesc('id')->first();
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class);
    }

    public function currentSlide(): ?Slide
    {
        return $this->current_slide_id ? $this->slides()->find($this->current_slide_id) : null;
    }

    public function isPresenterKey(string $key): bool
    {
        return $key !== '' && hash_equals($this->presenter_key_hash, hash('sha256', $key));
    }

    public function getPresenterKey(): ?string
    {
        if (! $this->presenter_key_encrypted) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->presenter_key_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ensurePresenterKey(): string
    {
        $existing = $this->getPresenterKey();
        if ($existing !== null && $this->isPresenterKey($existing)) {
            return $existing;
        }

        return $this->rotatePresenterKey();
    }

    public function rotatePresenterKey(): string
    {
        $key = \Illuminate\Support\Str::random(40);
        $this->forceFill([
            'presenter_key_hash' => hash('sha256', $key),
            'presenter_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($key),
        ])->save();

        return $key;
    }

    public function coverImageUrl(): ?string
    {
        if (! $this->cover_image_key) {
            return null;
        }

        return route('webinar.cover', $this->slug);
    }

    public function normalizedCoverTheme(): string
    {
        $theme = (string) ($this->cover_theme ?? 'pistachio');

        return in_array($theme, self::COVER_THEMES, true) ? $theme : 'pistachio';
    }
}
