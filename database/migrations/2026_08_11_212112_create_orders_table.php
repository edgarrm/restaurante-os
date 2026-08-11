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
        Schema::create('orders', function (Blueprint $table) {
            // Tabla mínima: solo lo que DeleteTableAction necesita para saber
            // si una mesa tiene una cuenta activa (ver
            // _ai/specs/gestion-mesas.spec.md). El resto del dominio de
            // Order (items, cierre, relación con pagos) lo completa
            // _ai/specs/toma-de-pedido.spec.md (#5) — ver data-model.md para
            // el esquema completo.
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('table_id')->constrained();
            $table->foreignId('opened_by')->constrained('users');
            $table->enum('status', ['abierta', 'enviada_cocina', 'lista', 'por_cobrar', 'pagada', 'cancelada'])->default('abierta');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['table_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
