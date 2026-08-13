<?php

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('GET /dashboard suma en salesTotal los Payment.amount de hoy, no de otros días', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->admin->id]);
    Payment::factory()->for($order)->create(['collected_by' => $this->admin->id, 'amount' => 100, 'paid_at' => now()]);
    Payment::factory()->for($order)->create(['collected_by' => $this->admin->id, 'amount' => 50, 'paid_at' => now()]);
    // Pago de ayer — no debe contar.
    Payment::factory()->for($order)->create(['collected_by' => $this->admin->id, 'amount' => 999, 'paid_at' => now()->subDay()]);

    $response = $this->actingAs($this->admin)->get(route('dashboard'), inertiaXhrHeaders());

    $response->assertOk();
    expect((float) $response->json('props.salesTotal'))->toBe(150.0);
});

test('GET /dashboard sin ventas hoy devuelve salesTotal en 0', function () {
    $response = $this->actingAs($this->admin)->get(route('dashboard'), inertiaXhrHeaders());

    $response->assertOk();
    expect((float) $response->json('props.salesTotal'))->toBe(0.0);
});

test('GET /dashboard devuelve activeTables con status != libre, excluyendo las libres', function () {
    Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa Libre']);
    Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa Ocupada', 'status' => TableStatus::Ocupada]);
    Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa Por Cobrar', 'status' => TableStatus::PorCobrar]);

    $response = $this->actingAs($this->admin)->get(route('dashboard'), inertiaXhrHeaders());

    $names = collect($response->json('props.activeTables'))->pluck('name');
    expect($names->all())->toEqualCanonicalizing(['Mesa Ocupada', 'Mesa Por Cobrar']);
});

test('GET /dashboard excluye reservas canceladas y de otros días de todayReservations', function () {
    Reservation::factory()->for($this->tenant, 'tenant')->create([
        'customer_name' => 'Confirmada hoy',
        'reserved_at' => today()->setTime(14, 0),
        'status' => ReservationStatus::Confirmada,
    ]);
    Reservation::factory()->for($this->tenant, 'tenant')->create([
        'customer_name' => 'Cancelada hoy',
        'reserved_at' => today()->setTime(15, 0),
        'status' => ReservationStatus::Cancelada,
    ]);
    Reservation::factory()->for($this->tenant, 'tenant')->create([
        'customer_name' => 'Confirmada mañana',
        'reserved_at' => today()->addDay()->setTime(14, 0),
        'status' => ReservationStatus::Confirmada,
    ]);

    $response = $this->actingAs($this->admin)->get(route('dashboard'), inertiaXhrHeaders());

    $names = collect($response->json('props.todayReservations'))->pluck('customer_name');
    expect($names->all())->toBe(['Confirmada hoy']);
});

test('usuario con role=mesero accede a /dashboard → 403', function () {
    $mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($mesero)->get(route('dashboard'));

    $response->assertForbidden();
});

test('usuario con role=cocina accede a /dashboard → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->actingAs($cocina)->get(route('dashboard'));

    $response->assertForbidden();
});

test('F-05: GET /dashboard no mezcla ventas, mesas ni reservas de otro restaurante', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);

    $adminB = User::factory()->for($tenantB, 'tenant')->admin()->create();
    $tableB = Table::factory()->for($tenantB, 'tenant')->create(['name' => 'Mesa de B', 'status' => TableStatus::Ocupada]);
    $orderB = Order::factory()->for($tenantB, 'tenant')->for($tableB)->create(['opened_by' => $adminB->id]);
    Payment::factory()->for($orderB)->create(['collected_by' => $adminB->id, 'amount' => 500, 'paid_at' => now()]);
    Reservation::factory()->for($tenantB, 'tenant')->create([
        'customer_name' => 'Reserva de B',
        'reserved_at' => today()->setTime(14, 0),
    ]);

    $response = $this->actingAs($this->admin)->get(route('dashboard'), inertiaXhrHeaders());

    $response->assertOk();
    expect((float) $response->json('props.salesTotal'))->toBe(0.0)
        ->and($response->json('props.activeTables'))->toBe([])
        ->and($response->json('props.todayReservations'))->toBe([]);
});

test('login de un usuario role=admin redirige a /dashboard', function () {
    $admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('login de un usuario role=mesero redirige a /mesas, no a /dashboard', function () {
    $mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->post(route('login.store'), [
        'email' => $mesero->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('mesas.index', absolute: false));
});

test('login de un usuario role=cocina redirige a /cocina, no a /dashboard', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->post(route('login.store'), [
        'email' => $cocina->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('cocina.index', absolute: false));
});
