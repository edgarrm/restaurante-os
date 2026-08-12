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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            // A diferencia de OrderItem/Payment (#5/#7, heredan el
            // aislamiento vía Order), Reservation necesita su propio
            // tenant_id: table_id es nullable, así que no hay una relación
            // padre confiable de la que heredarlo (ver _ai/docs/data-model.md
            // y decision-log.md, PASO 0 de reservas.spec.md #8).
            $table->string('tenant_id');
            $table->foreignId('table_id')->nullable()->constrained();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->unsignedInteger('party_size');
            $table->timestamp('reserved_at');
            $table->enum('status', ['confirmada', 'sentada', 'cancelada'])->default('confirmada');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index('reserved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
