<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefaultEmailTemplate extends Model
{
    protected $fillable = [
        'language_code',
        'template_key',
        'group_label',
        'label',
        'audience',
        'timing',
        'subject',
        'body_html',
        'attach_recording',
    ];

    protected $casts = [
        'attach_recording' => 'boolean',
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(EmailLanguage::class, 'language_code', 'code');
    }
}
