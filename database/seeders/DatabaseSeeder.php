<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // El usuario demo del starter kit se quitó: `users.tenant_id` ahora
        // es requerido (ver ADR-006), y no hay un tenant al que asociarlo en
        // un seeder sin contexto. Para dar de alta un restaurante de prueba
        // con su primer admin, usa `php artisan tenants:onboard`.
    }
}
