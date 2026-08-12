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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            // string, no bigint: tenants.id es un UUID (ver ADR-006 y la
            // migración equivalente en tables/menu_items/users).
            $table->string('tenant_id');
            $table->string('name');
            $table->string('unit');
            $table->decimal('quantity_on_hand', 10, 3)->default(0);
            $table->decimal('low_stock_threshold', 10, 3)->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
