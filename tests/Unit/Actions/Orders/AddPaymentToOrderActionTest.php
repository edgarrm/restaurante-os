<?php

use App\Actions\Orders\AddPaymentToOrderAction;
use App\Actions\Orders\CloseOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Exceptions\Orders\InsufficientPaymentException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

/**
 * Crea una orden `por_cobrar` con dos ítems cuyo total suma 130.00 (2×50.00 +
 * 1×30.00), lista para dividir entre varios pagos.
 */
function ordenParaDividir(): Order
{
    $table = Table::factory()->create(['status' => TableStatus::PorCobrar]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create([
        'opened_by' => $mesero->id,
        'status' => OrderStatus::PorCobrar,
    ]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 2, 'unit_price' => 50.00]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 1, 'unit_price' => 30.00]);

    return $order;
}

test('pago parcial no cierra la orden ni libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();

    $result = (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::PorCobrar)
        ->and($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and($order->table->fresh()->status)->toBe(TableStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(50.00);
});

test('segundo pago que completa el total cierra la orden y libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();

    (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);
    $result = (new AddPaymentToOrderAction)->handle($order, 80.00, PaymentMethod::Tarjeta, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($order->table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::count())->toBe(2);
});

test('suma de pagos que excede el total se acepta y cierra la orden', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();

    (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);
    $result = (new AddPaymentToOrderAction)->handle($order, 100.00, PaymentMethod::Tarjeta, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(2)
        ->and((float) Payment::sum('amount'))->toBe(150.00);
});

test('orden ya pagada no crea un segundo Payment (idempotente)', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $order->update(['status' => OrderStatus::Pagada]);
    Payment::factory()->for($order)->create(['collected_by' => $collectedBy->id, 'amount' => 130.00]);

    $result = (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(1);
});

test('F-03: cada pago parcial registra collected_by igual al usuario autenticado pasado a la Action', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();

    (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);

    expect(Payment::sole()->collected_by)->toBe($collectedBy->id);
});

test('CloseOrderAction: con pagos parciales previos que ya cubren el total, un monto adicional cierra la orden sin lanzar excepción', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    (new AddPaymentToOrderAction)->handle($order, 130.00, PaymentMethod::Efectivo, $collectedBy);
    // Vuelve a abrir artificialmente el escenario: simula que la orden seguía
    // abierta con $130 ya pagados de más (caso límite, no ocurre en la UI
    // real, pero prueba que la Action no rompe con pagos previos presentes).
    $order->update(['status' => OrderStatus::PorCobrar]);
    $order->table->forceFill(['status' => TableStatus::PorCobrar])->save();

    $result = (new CloseOrderAction)->handle($order->fresh(), 0.01, PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(2);
});

test('CloseOrderAction: con pagos parciales previos insuficientes, un monto que tampoco alcanza lanza InsufficientPaymentException', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    (new AddPaymentToOrderAction)->handle($order, 50.00, PaymentMethod::Efectivo, $collectedBy);

    expect(fn () => (new CloseOrderAction)->handle($order->fresh(), 10.00, PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(InsufficientPaymentException::class, 'El monto no cubre el total de la cuenta ($130.00).');

    expect($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and(Payment::count())->toBe(1);
});

test('handleForItems: pago de grupo que no cubre el saldo no cierra la orden ni libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $result = (new AddPaymentToOrderAction)->handleForItems($order, [$item->id], PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::PorCobrar)
        ->and($order->table->fresh()->status)->toBe(TableStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(30.00)
        ->and($item->fresh()->payment_id)->toBe(Payment::sole()->id);
});

test('handleForItems: pago de grupo que cubre el total cierra la orden y libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemIds = $order->items()->pluck('id')->all();

    $result = (new AddPaymentToOrderAction)->handleForItems($order, $itemIds, PaymentMethod::Tarjeta, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and($order->table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(130.00);
});

test('handleForItems: ítem ya asignado a un pago previo lanza ValidationException y no crea un segundo Payment', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $item = $order->items()->where('unit_price', 30.00)->sole();
    (new AddPaymentToOrderAction)->handleForItems($order, [$item->id], PaymentMethod::Efectivo, $collectedBy);

    expect(fn () => (new AddPaymentToOrderAction)->handleForItems($order->fresh(), [$item->id], PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(1);
});

test('handleForItems: ítem que no pertenece a la orden lanza ValidationException', function () {
    $order = ordenParaDividir();
    $otraOrden = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemAjeno = $otraOrden->items()->first();

    expect(fn () => (new AddPaymentToOrderAction)->handleForItems($order, [$itemAjeno->id], PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(0);
});

test('handleForItems: orden ya pagada no crea un segundo Payment (idempotente)', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $order->update(['status' => OrderStatus::Pagada]);
    Payment::factory()->for($order)->create(['collected_by' => $collectedBy->id, 'amount' => 130.00]);
    $itemId = $order->items()->first()->id;

    $result = (new AddPaymentToOrderAction)->handleForItems($order, [$itemId], PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(1);
});

test('F-03: handleForItems registra collected_by igual al usuario autenticado pasado a la Action', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemId = $order->items()->first()->id;

    (new AddPaymentToOrderAction)->handleForItems($order, [$itemId], PaymentMethod::Efectivo, $collectedBy);

    expect(Payment::sole()->collected_by)->toBe($collectedBy->id);
});
