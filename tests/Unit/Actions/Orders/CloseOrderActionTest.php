<?php

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

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

/**
 * Crea una orden `lista` con dos ítems cuyo total suma 130.00 (2×50.00 +
 * 1×30.00), lista para cerrar.
 */
function ordenParaCobrar(): Order
{
    $table = Table::factory()->create(['status' => TableStatus::PorCobrar]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create([
        'opened_by' => $mesero->id,
        'status' => OrderStatus::Lista,
    ]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 2, 'unit_price' => 50.00]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 1, 'unit_price' => 30.00]);

    return $order;
}

test('monto igual al total crea Payment, la orden pasa a pagada y la mesa a libre', function () {
    $order = ordenParaCobrar();
    $collectedBy = User::factory()->create();

    $result = (new CloseOrderAction)->handle($order, 130.00, PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($order->table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::count())->toBe(1);

    $payment = Payment::sole();
    expect((float) $payment->amount)->toBe(130.00)
        ->and($payment->method)->toBe(PaymentMethod::Efectivo);
});

test('monto menor al total lanza excepción de dominio', function () {
    $order = ordenParaCobrar();
    $collectedBy = User::factory()->create();

    expect(fn () => (new CloseOrderAction)->handle($order, 100.00, PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(InsufficientPaymentException::class, 'El monto no cubre el total de la cuenta ($130.00).');

    expect($order->fresh()->status)->toBe(OrderStatus::Lista)
        ->and(Payment::count())->toBe(0);
});

test('monto mayor al total acepta y registra el monto real', function () {
    $order = ordenParaCobrar();
    $collectedBy = User::factory()->create();

    (new CloseOrderAction)->handle($order, 200.00, PaymentMethod::Tarjeta, $collectedBy);

    expect((float) Payment::sole()->amount)->toBe(200.00)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pagada);
});

test('orden ya pagada no crea un segundo Payment (idempotente)', function () {
    $order = ordenParaCobrar();
    $collectedBy = User::factory()->create();
    $order->update(['status' => OrderStatus::Pagada]);
    Payment::factory()->for($order)->create(['collected_by' => $collectedBy->id, 'amount' => 130.00]);

    $result = (new CloseOrderAction)->handle($order, 130.00, PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(1);
});

test('F-03: el Payment creado tiene collected_by igual al usuario autenticado pasado a la Action', function () {
    $order = ordenParaCobrar();
    $collectedBy = User::factory()->create();

    (new CloseOrderAction)->handle($order, 130.00, PaymentMethod::Efectivo, $collectedBy);

    expect(Payment::sole()->collected_by)->toBe($collectedBy->id);
});
