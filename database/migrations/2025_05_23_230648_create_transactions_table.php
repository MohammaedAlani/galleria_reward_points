<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('add_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('location')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('transaction_date')->nullable();
            $table->string('transaction_amount')->nullable();
            $table->string('transaction_number')->nullable();
            $table->string('transaction_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
