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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            // string, no bigint: tenants.id es un UUID (ver ADR-006 y la
            // migración equivalente en users).
            $table->string('tenant_id');
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->enum('status', ['libre', 'ocupada', 'por_cobrar'])->default('libre');
            $table->softDeletes();
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
        Schema::dropIfExists('tables');
    }
};
