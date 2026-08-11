<?php

use App\Actions\Tables\DeleteTableAction;
use App\Enums\OrderStatus;
use App\Exceptions\Tables\TableHasActiveOrderException;
use App\Models\Order;
use App\Models\Table;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('elimina la mesa cuando no tiene órdenes activas', function () {
    $table = Table::factory()->create();

    (new DeleteTableAction)->handle($table);

    expect(Table::find($table->id))->toBeNull()
        ->and(Table::withTrashed()->find($table->id))->not->toBeNull();
});

test('lanza excepción de dominio si la mesa tiene una orden activa y no la elimina', function (OrderStatus $status) {
    $table = Table::factory()->create();
    Order::factory()->for($table)->create(['status' => $status]);

    expect(fn () => (new DeleteTableAction)->handle($table))
        ->toThrow(TableHasActiveOrderException::class, 'No se puede eliminar una mesa con una cuenta activa.');

    expect(Table::find($table->id))->not->toBeNull();
})->with([
    'abierta' => OrderStatus::Abierta,
    'enviada_cocina' => OrderStatus::EnviadaCocina,
]);

test('permite eliminar la mesa si sus órdenes ya no están activas', function (OrderStatus $status) {
    $table = Table::factory()->create();
    Order::factory()->for($table)->create(['status' => $status]);

    (new DeleteTableAction)->handle($table);

    expect(Table::find($table->id))->toBeNull();
})->with([
    'pagada' => OrderStatus::Pagada,
    'cancelada' => OrderStatus::Cancelada,
]);
