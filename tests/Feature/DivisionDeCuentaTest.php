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
 * Crea, dentro del tenant de prueba, una mesa `por_cobrar` con una orden
 * cuyo total suma 130.00 (2×50.00 + 1×30.00), lista para dividir.
 */
function mesaPorCobrarDe130(): Table
{
    $table = Table::factory()->for(test()->tenant, 'tenant')->create(['status' => TableStatus::PorCobrar]);
    $order = Order::factory()->for(test()->tenant, 'tenant')->for($table)->create([
        'opened_by' => test()->mesero->id,
        'status' => OrderStatus::PorCobrar,
    ]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for(test()->tenant, 'tenant'))
        ->create(['quantity' => 2, 'unit_price' => 50.00]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for(test()->tenant, 'tenant'))
        ->create(['quantity' => 1, 'unit_price' => 30.00]);

    return $table;
}

test('POST /mesas/{table}/cobro/pagos con monto parcial registra el pago y no cierra la cuenta', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('cobro.show', $table));
    expect($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and($table->fresh()->status)->toBe(TableStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(50.00);
});

test('un segundo pago que completa el saldo cierra la cuenta y libera la mesa', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();

    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);
    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $table), ['amount' => 80.00, 'method' => PaymentMethod::Tarjeta->value]);

    $response->assertRedirect(route('mesas.index'));
    expect($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::count())->toBe(2);
});

test('GET /mesas/{table}/cobro después de un pago parcial muestra el saldo pendiente y el historial', function () {
    $table = mesaPorCobrarDe130();

    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $table), inertiaXhrHeaders());

    $response->assertOk();
    $order = $response->json('props.order');
    expect(collect($order['payments']))->toHaveCount(1)
        ->and((float) $order['payments'][0]['amount'])->toBe(50.00);
});

test('usuario con role=cocina accede a cobro.pagos.store → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $table = mesaPorCobrarDe130();

    $response = $this->actingAs($cocina)
        ->post(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertForbidden();
});

test('F-03: un collected_by enviado en el body de cobro.pagos.store es ignorado', function () {
    $table = mesaPorCobrarDe130();
    $otroUsuario = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $this->actingAs($this->mesero)->post(route('cobro.pagos.store', $table), [
        'amount' => 50.00,
        'method' => PaymentMethod::Efectivo->value,
        'collected_by' => $otroUsuario->id,
    ]);

    expect(Payment::sole()->collected_by)->toBe($this->mesero->id);
});

test('F-05: mesero del restaurante A pide cobro.pagos.store de la mesa de otro restaurante → 404', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $tableB), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertNotFound();
});

test('pago parcial de monto ≤ 0 devuelve 422', function () {
    $table = mesaPorCobrarDe130();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.store', $table), ['amount' => 0, 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

test('POST cobro.pagos.store sobre una orden ya pagada es idempotente, sin nuevo Payment', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $order->update(['status' => OrderStatus::Pagada]);
    $table->forceFill(['status' => TableStatus::Libre])->save();
    Payment::factory()->for($order)->create(['collected_by' => $this->mesero->id, 'amount' => 130.00]);

    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.store', $table), ['amount' => 50.00, 'method' => PaymentMethod::Efectivo->value]);

    expect(Payment::count())->toBe(1);
});

test('POST cobro.pagos.porItems con ítems válidos y monto parcial registra el pago y no cierra la cuenta', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('cobro.show', $table));
    expect($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(30.00)
        ->and($item->fresh()->payment_id)->toBe(Payment::sole()->id);
});

test('POST cobro.pagos.porItems con ítems que completan el saldo cierra la cuenta y libera la mesa', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemIds = $order->items()->pluck('id')->all();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => $itemIds, 'method' => PaymentMethod::Tarjeta->value]);

    $response->assertRedirect(route('mesas.index'));
    expect($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($table->fresh()->status)->toBe(TableStatus::Libre);
});

test('POST cobro.pagos.porItems con item_ids vacío devuelve 422', function () {
    $table = mesaPorCobrarDe130();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

test('POST cobro.pagos.porItems con un ítem ya asignado a otro pago devuelve 422', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();
    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Tarjeta->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(1);
});

test('POST cobro.pagos.porItems con un ítem que no pertenece a la orden devuelve 422', function () {
    $table = mesaPorCobrarDe130();
    $otraMesa = mesaPorCobrarDe130();
    $itemAjeno = $otraMesa->orders()->sole()->items()->first();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [$itemAjeno->id], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

test('usuario con role=cocina accede a cobro.pagos.porItems → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemId = $order->items()->first()->id;

    $response = $this->actingAs($cocina)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$itemId], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertForbidden();
});

test('F-03: un collected_by enviado en el body de cobro.pagos.porItems es ignorado', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemId = $order->items()->first()->id;
    $otroUsuario = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $this->actingAs($this->mesero)->post(route('cobro.pagos.porItems', $table), [
        'item_ids' => [$itemId],
        'method' => PaymentMethod::Efectivo->value,
        'collected_by' => $otroUsuario->id,
    ]);

    expect(Payment::sole()->collected_by)->toBe($this->mesero->id);
});

test('F-05: mesero del restaurante A pide cobro.pagos.porItems de la mesa de otro restaurante → 404', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $tableB), ['item_ids' => [1], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertNotFound();
});

test('GET /mesas/{table}/cobro tras un pago por ítems muestra el payment_id asignado en las props', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $table), inertiaXhrHeaders());

    $response->assertOk();
    $items = collect($response->json('props.order.items'));
    $paidItem = $items->firstWhere('id', $item->id);

    expect($paidItem['payment_id'])->not->toBeNull();
});
