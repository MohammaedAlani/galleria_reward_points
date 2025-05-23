<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'add_by',
        'approved_by',
        'transaction_type',
        'transaction_date',
        'transaction_amount',
        'transaction_number',
        'transaction_status',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
