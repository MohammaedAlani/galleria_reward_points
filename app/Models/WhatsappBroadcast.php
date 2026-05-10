<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappBroadcast extends Model
{
    protected $fillable = [
        'created_by',
        'body',
        'media_type',
        'media_url',
        'link_url',
        'recipient_filter',
        'total',
        'sent',
        'failed',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'recipient_filter' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'broadcast_id');
    }
}
