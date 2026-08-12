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
        // Sin `tenant_id` propio, igual que `order_items` — Payment hereda
        // el aislamiento vía Order (ver _ai/docs/data-model.md y
        // app/Models/Payment.php).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('collected_by')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->enum('method', ['efectivo', 'tarjeta', 'transferencia']);
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
