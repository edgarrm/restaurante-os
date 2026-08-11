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
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            // string, no bigint: tenants.id es un UUID (ver ADR-006 y la
            // migración equivalente en tables/users).
            $table->string('tenant_id');
            $table->string('name');
            // string simple en MVP, no tabla propia (ver
            // _ai/docs/data-model.md, nota de alcance de MenuItem).
            $table->string('category');
            $table->decimal('price', 10, 2);
            $table->boolean('available')->default(true);
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
        Schema::dropIfExists('menu_items');
    }
};
