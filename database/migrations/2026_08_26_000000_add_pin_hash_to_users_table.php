<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F-07 (_ai/docs/threat-model.md), _ai/specs/bloqueo-tablet-pin.spec.md:
     * PIN corto (4 dígitos) de re-autenticación antes de cobrar, hasheado
     * con Hash::make() (mismo hasher que `password`) — nunca en texto
     * plano. Nullable: configurar el PIN es autoservicio (cada staff lo
     * fija en `/settings/pin`), no algo que el admin asigna al crear la
     * cuenta, así que toda cuenta existente empieza sin PIN.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_hash');
        });
    }
};
