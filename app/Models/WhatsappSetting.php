<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetting extends Model
{
    protected $fillable = [
        'instance_id',
        'token',
        'status',
        'qr',
        'qr_fetched_at',
        'connected_at',
    ];

    protected $casts = [
        'qr_fetched_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    protected $hidden = [
        'token',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
