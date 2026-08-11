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
        // Tabla mínima: solo lo que el Unit test de UpdateMenuItemAction
        // necesita para probar que `unit_price` es un snapshot que no se
        // recalcula cuando cambia `menu_items.price` (ver
        // _ai/specs/gestion-menu.spec.md). Sin `tenant_id` propio — OrderItem
        // hereda el aislamiento vía Order (ver _ai/docs/data-model.md). El
        // resto del dominio de OrderItem (Actions, controller, rutas) lo
        // completa _ai/specs/toma-de-pedido.spec.md (#5).
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('menu_item_id')->constrained();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->enum('status', ['pendiente', 'preparando', 'listo', 'servido'])->default('pendiente');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
