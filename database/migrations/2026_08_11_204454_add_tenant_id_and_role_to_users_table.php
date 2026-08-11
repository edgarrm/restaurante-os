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
        Schema::table('users', function (Blueprint $table) {
            // string, no bigint: tenants.id es un UUID generado por
            // Stancl\Tenancy\UUIDGenerator (ver config/tenancy.php), no un
            // autoincremental. Ver ADR-006 y _ai/docs/data-model.md.
            $table->string('tenant_id')->after('id');
            $table->enum('role', ['admin', 'mesero', 'cocina'])->default('mesero')->after('email');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'role']);
        });
    }
};
