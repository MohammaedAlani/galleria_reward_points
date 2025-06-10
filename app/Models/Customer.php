<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'card_number',
        'total_points',
        'total_spent',
        'last_transaction',
        'last_transaction_date',
        'last_transaction_amount',
    ];

    protected $appends = [
        'total_points_can_use',
        'points_amount',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'total_spent' => 'float',
        'last_transaction_date' => 'datetime',
    ];

    /**
     * Get the total points for the customer.
     *
     * @return int
     */
    public function getTotalPointsCanUseAttribute(): int
    {
        return $this->total_points - $this->total_spent;
    }

    /**
     * Get the points amount for the customer.
     *
     * @return float
     */
    public function getPointsAmountAttribute(): float
    {
        return $this->total_points_can_use * config('points.iqd_per_point', 4);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
