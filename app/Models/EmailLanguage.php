<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailLanguage extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function defaultTemplates(): HasMany
    {
        return $this->hasMany(DefaultEmailTemplate::class, 'language_code', 'code');
    }
}
