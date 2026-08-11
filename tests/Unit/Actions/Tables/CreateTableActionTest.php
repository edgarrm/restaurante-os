<?php

use App\Actions\Tables\CreateTableAction;
use App\Enums\TableStatus;
use App\Models\Table;
use Illuminate\Validation\ValidationException;

/**
 * CreateTableAction rellena tenant_id vía BelongsToTenant, que solo autorrellena
 * cuando tenancy está inicializada (ver trait) — a diferencia de un Feature test,
 * aquí no hay petición HTTP que dispare InitializeTenancyByDomain, así que se
 * inicializa manualmente.
 */
beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('crea una mesa con status=libre por defecto', function () {
    $table = (new CreateTableAction)->handle(['name' => 'Mesa 4', 'capacity' => 4]);

    expect($table->name)->toBe('Mesa 4')
        ->and($table->capacity)->toBe(4)
        ->and($table->status)->toBe(TableStatus::Libre);
});

test('capacidad menor o igual a 0 lanza error de validación y no crea la mesa', function (int $capacity) {
    expect(fn () => (new CreateTableAction)->handle(['name' => 'Mesa 4', 'capacity' => $capacity]))
        ->toThrow(ValidationException::class);

    expect(Table::count())->toBe(0);
})->with([0, -1]);

test('nombre vacío lanza error de validación', function () {
    expect(fn () => (new CreateTableAction)->handle(['name' => '', 'capacity' => 4]))
        ->toThrow(ValidationException::class);
});
