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
        // Split por ítems (REDEV-29, _ai/specs/division-de-cuenta.spec.md,
        // "Ampliación"): un OrderItem asignado a un Payment queda
        // "cobrado" por ese grupo. Nullable a propósito — un ítem sin
        // asignar no bloquea el cierre de la cuenta (decisión de
        // producto, PASO 0 de REDEV-29): el cierre sigue siendo 100% por
        // monto acumulado.
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
