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

    protected $with = [
        'addByUser',
        'approvedByUser',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function addByUser(){
        return $this->belongsTo(User::class, 'add_by');
    }

    public function approvedByUser(){
        return $this->belongsTo(User::class, 'approved_by');
    }
}
