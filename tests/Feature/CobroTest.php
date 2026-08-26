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
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();

    // F-07 (_ai/specs/bloqueo-tablet-pin.spec.md): los endpoints POST de
    // cobro ahora exigen un PIN configurado + verificado recientemente en
    // sesión. Este spec no es sobre el PIN — se simula ya-configurado y
    // ya-verificado para no acoplar cada test existente a ese flujo
    // (cubierto por su propio spec/tests).
    $this->mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->withPaymentPin()->create();
    $this->withSession(['pin_verified_at' => now()->timestamp]);
});

/**
 * Crea, dentro del tenant de prueba, una mesa ocupada con una orden `lista`
 * cuyo total suma 130.00 (2×50.00 + 1×30.00).
 */
function mesaConCuentaDe130(): Table
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

test('GET /mesas/{table}/cobro devuelve el detalle de la orden y marca la mesa por_cobrar', function () {
    $table = mesaConCuentaDe130();

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $table), inertiaXhrHeaders());

    $response->assertOk();
    $order = $response->json('props.order');
    expect($order['status'])->toBe(OrderStatus::PorCobrar->value)
        ->and(collect($order['items']))->toHaveCount(2)
        ->and($table->fresh()->status)->toBe(TableStatus::PorCobrar);
});

test('POST /mesas/{table}/cobro con monto suficiente cierra la cuenta y libera la mesa', function () {
    $table = mesaConCuentaDe130();
    $order = $table->orders()->sole();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('mesas.index'));
    expect($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::sole()->collected_by)->toBe($this->mesero->id);
});

test('POST /mesas/{table}/cobro con monto insuficiente devuelve 422', function () {
    $table = mesaConCuentaDe130();
    $order = $table->orders()->sole();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.close', $table), ['amount' => 100.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect($response->json('errors.amount.0'))->toBe('El monto no cubre el total de la cuenta ($130.00).')
        ->and($order->fresh()->status)->not->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(0);
});

test('POST /mesas/{table}/cobro sobre una orden ya pagada es idempotente, sin nuevo Payment', function () {
    $table = mesaConCuentaDe130();
    $order = $table->orders()->sole();
    $order->update(['status' => OrderStatus::Pagada]);
    $table->forceFill(['status' => TableStatus::Libre])->save();
    Payment::factory()->for($order)->create(['collected_by' => $this->mesero->id, 'amount' => 130.00]);

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.close', $table), ['amount' => 130.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('mesas.index'));
    expect(Payment::count())->toBe(1);
});

test('usuario con role=cocina accede a /mesas/{table}/cobro → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $table = mesaConCuentaDe130();

    $response = $this->actingAs($cocina)->get(route('cobro.show', $table));

    $response->assertForbidden();
});

test('F-03: un collected_by enviado en el request es ignorado — se usa el usuario autenticado', function () {
    $table = mesaConCuentaDe130();
    $order = $table->orders()->sole();
    $otroUsuario = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $this->actingAs($this->mesero)->post(route('cobro.close', $table), [
        'amount' => 130.00,
        'method' => PaymentMethod::Efectivo->value,
        'collected_by' => $otroUsuario->id,
    ]);

    expect(Payment::sole()->collected_by)->toBe($this->mesero->id);
});

test('F-05: mesero del restaurante A pide /mesas/{mesa_del_restaurante_B}/cobro → 404', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $tableB), inertiaXhrHeaders());

    $response->assertNotFound();
});
