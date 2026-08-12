<?php

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();
});

test('GET /reservas devuelve las reservas de hoy ordenadas por reserved_at', function () {
    $tarde = Reservation::factory()->for($this->tenant, 'tenant')->create([
        'customer_name' => 'Reserva de las 8pm',
        'reserved_at' => today()->setTime(20, 0),
    ]);
    $temprano = Reservation::factory()->for($this->tenant, 'tenant')->create([
        'customer_name' => 'Reserva de las 2pm',
        'reserved_at' => today()->setTime(14, 0),
    ]);
    // No debe aparecer: es de mañana.
    Reservation::factory()->for($this->tenant, 'tenant')->create([
        'reserved_at' => today()->addDay()->setTime(14, 0),
    ]);

    $response = $this->actingAs($this->mesero)
        ->get(route('reservas.index'), inertiaXhrHeaders());

    $response->assertOk();
    $names = collect($response->json('props.reservations'))->pluck('customer_name');
    expect($names->all())->toBe(['Reserva de las 2pm', 'Reserva de las 8pm']);
});

test('GET /reservas incluye reservas canceladas del día, no solo las activas', function () {
    Reservation::factory()->for($this->tenant, 'tenant')->create([
        'reserved_at' => today()->setTime(14, 0),
        'status' => ReservationStatus::Cancelada,
    ]);

    $response = $this->actingAs($this->mesero)
        ->get(route('reservas.index'), inertiaXhrHeaders());

    expect(collect($response->json('props.reservations')))->toHaveCount(1);
});

test('POST /reservas con datos válidos crea la reserva', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();

    $response = $this->actingAs($this->mesero)->post(route('reservas.store'), [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 4,
        'reserved_at' => now()->addDay()->toDateTimeString(),
        'table_id' => $table->id,
    ]);

    $response->assertRedirect(route('reservas.index'));
    $reservation = Reservation::sole();
    expect($reservation->customer_name)->toBe('Ana Pérez')
        ->and($reservation->status)->toBe(ReservationStatus::Confirmada)
        ->and($reservation->table_id)->toBe($table->id);
});

test('POST /reservas con fecha pasada devuelve 422', function () {
    $response = $this->actingAs($this->mesero)->postJson(route('reservas.store'), [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 2,
        'reserved_at' => now()->subDay()->toDateTimeString(),
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('La hora de la reserva debe ser futura.')
        ->and(Reservation::count())->toBe(0);
});

test('usuario con role=cocina accede a /reservas → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->actingAs($cocina)->get(route('reservas.index'));

    $response->assertForbidden();
});

test('F-05: GET /reservas no expone customer_name ni customer_phone de otro restaurante', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    Reservation::factory()->for($tenantB, 'tenant')->create([
        'customer_name' => 'Cliente de B',
        'customer_phone' => '999-0000',
        'reserved_at' => today()->setTime(15, 0),
    ]);

    $response = $this->actingAs($this->mesero)
        ->get(route('reservas.index'), inertiaXhrHeaders());

    $response->assertOk();
    $payload = json_encode($response->json('props.reservations'));
    expect($payload)->not->toContain('Cliente de B')
        ->and($payload)->not->toContain('999-0000');
});

test('F-05: asignar table_id de una mesa de otro restaurante a una reserva falla', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->mesero)->post(route('reservas.store'), [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 2,
        'reserved_at' => now()->addDay()->toDateTimeString(),
        'table_id' => $tableB->id,
    ]);

    $response->assertNotFound();
    expect(Reservation::count())->toBe(0);
});
