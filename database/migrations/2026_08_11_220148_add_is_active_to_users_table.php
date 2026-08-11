<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * _ai/specs/gestion-staff.spec.md, Edge Cases: una cuenta que abrió
     * órdenes en el pasado no se elimina (rompería la FK de
     * `Order.opened_by`) — se desactiva en su lugar (login deshabilitado).
     * Default `true` para no afectar cuentas existentes.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
