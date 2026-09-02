<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();
});

/**
 * Crea, dentro del tenant de prueba, una mesa ocupada con una orden `lista`
 * cuyo total suma 130.00 (2×50.00 + 1×30.00). Mismo helper que
 * tests/Feature/CobroTest.php.
 */
function mesaConCuentaDe130ParaPin(): Table
{
    $table = Table::factory()->for(test()->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for(test()->tenant, 'tenant')->for($table)->create([
        'opened_by' => test()->mesero->id,
        'status' => OrderStatus::Lista,
    ]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for(test()->tenant, 'tenant'))
        ->create(['quantity' => 2, 'unit_price' => 50.00]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for(test()->tenant, 'tenant'))
        ->create(['quantity' => 1, 'unit_price' => 30.00]);

    return $table;
}

// --- Gate en los endpoints de cobro ---------------------------------------

test('POST /mesas/{table}/cobro sin PIN configurado devuelve el error pin_not_set', function () {
    $table = mesaConCuentaDe130ParaPin();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect($response->json('errors.pin_not_set.0'))->toBe('Configura tu PIN de cobro en Ajustes antes de cobrar.')
        ->and(Payment::count())->toBe(0);
});

test('POST /mesas/{table}/cobro con PIN configurado pero sin verificar en sesión devuelve el error pin', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();
    $table = mesaConCuentaDe130ParaPin();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect($response->json('errors.pin.0'))->toBe('Verifica tu PIN para continuar con el cobro.')
        ->and(Payment::count())->toBe(0);
});

test('verificación de hace 4 minutos deja pasar el cobro sin volver a pedir el PIN', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();
    $table = mesaConCuentaDe130ParaPin();

    $response = $this->actingAs($this->mesero)
        ->withSession(['pin_verified_at' => now()->subMinutes(4)->timestamp])
        ->post(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('mesas.index'));
    expect(Payment::count())->toBe(1);
});

test('verificación de hace 6 minutos vuelve a pedir el PIN', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();
    $table = mesaConCuentaDe130ParaPin();

    $response = $this->actingAs($this->mesero)
        ->withSession(['pin_verified_at' => now()->subMinutes(6)->timestamp])
        ->postJson(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect($response->json('errors.pin.0'))->toBe('Verifica tu PIN para continuar con el cobro.')
        ->and(Payment::count())->toBe(0);
});

test('el mismo gate aplica a cobro.pagos.store y cobro.pagos.porItems', function () {
    $table = mesaConCuentaDe130ParaPin();
    $order = $table->orders()->sole();
    $itemId = $order->items()->first()->id;

    $responseMonto = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);
    $responsePorItems = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [$itemId], 'method' => PaymentMethod::Efectivo->value]);

    $responseMonto->assertStatus(422);
    $responsePorItems->assertStatus(422);
    expect($responseMonto->json('errors.pin_not_set.0'))->not->toBeNull()
        ->and($responsePorItems->json('errors.pin_not_set.0'))->not->toBeNull()
        ->and(Payment::count())->toBe(0);
});

test('GET /mesas/{table}/cobro nunca pide PIN — solo el submit del pago está gateado', function () {
    $table = mesaConCuentaDe130ParaPin();

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $table), inertiaXhrHeaders());

    $response->assertOk();
});

// --- POST /pin/verificar ---------------------------------------------------

test('POST /pin/verificar con PIN correcto refresca pin_verified_at en la sesión', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();

    $response = $this->actingAs($this->mesero)->post(route('pin.verify'), ['pin' => '1234']);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    expect(session('pin_verified_at'))->toBeInt();
});

test('POST /pin/verificar con PIN incorrecto devuelve el error pin, sin cambiar la sesión', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();

    $response = $this->actingAs($this->mesero)->postJson(route('pin.verify'), ['pin' => '0000']);

    $response->assertStatus(422);
    expect($response->json('errors.pin.0'))->toBe('PIN incorrecto.')
        ->and(session('pin_verified_at'))->toBeNull();
});

test('POST /pin/verificar rate-limitado tras 5 intentos fallidos', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($this->mesero)->postJson(route('pin.verify'), ['pin' => '0000']);
    }

    $response = $this->actingAs($this->mesero)->postJson(route('pin.verify'), ['pin' => '1234']);

    $response->assertStatus(422);
    expect($response->json('errors.pin.0'))->toStartWith('Demasiados intentos.');
});

test('usuario con role=cocina no puede acceder a pin.verify → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->actingAs($cocina)->post(route('pin.verify'), ['pin' => '1234']);

    $response->assertForbidden();
});

// --- F-05: aislamiento entre tenants ----------------------------------------

test('F-05: el PIN de un usuario de otro tenant no verifica contra la sesión del tenant actual', function () {
    $this->mesero->pin_hash = Hash::make('1234');
    $this->mesero->save();

    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'tenant-b-pin-feature.test']);
    $userTenantB = User::factory()->for($tenantB, 'tenant')->mesero()->create();
    $userTenantB->pin_hash = Hash::make('5678');
    $userTenantB->save();

    $response = $this->actingAs($this->mesero)->postJson(route('pin.verify'), ['pin' => '5678']);

    $response->assertStatus(422);
    expect($response->json('errors.pin.0'))->toBe('PIN incorrecto.');
});

// --- Settings: fijar el PIN --------------------------------------------------

test('PUT /settings/pin con PIN y confirmación válidos actualiza pin_hash', function () {
    $response = $this->actingAs($this->mesero)
        ->put(route('pin.update'), ['pin' => '4321', 'pin_confirmation' => '4321']);

    $response->assertRedirect();
    expect(Hash::check('4321', $this->mesero->fresh()->pin_hash))->toBeTrue();
});

test('PUT /settings/pin sin confirmación coincidente devuelve 422 y no cambia el PIN', function () {
    $response = $this->actingAs($this->mesero)
        ->putJson(route('pin.update'), ['pin' => '4321', 'pin_confirmation' => '0000']);

    $response->assertStatus(422);
    expect($this->mesero->fresh()->pin_hash)->toBeNull();
});

test('GET /settings/pin es accesible para los 3 roles (autoservicio, sin restricción de rol)', function (string $role) {
    $user = User::factory()->for($this->tenant, 'tenant')->{$role}()->create();

    $response = $this->actingAs($user)->get(route('pin.edit'), inertiaXhrHeaders());

    $response->assertOk();
})->with(['admin', 'mesero', 'cocina']);
