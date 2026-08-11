<?php

use App\Actions\Tables\UpdateTableAction;
use App\Enums\TableStatus;
use App\Models\Table;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('actualiza nombre y capacidad sin afectar status', function () {
    $table = Table::factory()->create(['name' => 'Mesa 1', 'capacity' => 2]);
    $table->forceFill(['status' => TableStatus::Ocupada])->save();

    $updated = (new UpdateTableAction)->handle($table, ['name' => 'Mesa Terraza 1', 'capacity' => 6]);

    expect($updated->name)->toBe('Mesa Terraza 1')
        ->and($updated->capacity)->toBe(6)
        ->and($updated->status)->toBe(TableStatus::Ocupada);
});

test('capacidad menor o igual a 0 lanza error de validación y no modifica la mesa', function (int $capacity) {
    $table = Table::factory()->create(['name' => 'Mesa 1', 'capacity' => 2]);

    expect(fn () => (new UpdateTableAction)->handle($table, ['name' => 'Mesa 1', 'capacity' => $capacity]))
        ->toThrow(ValidationException::class);

    expect($table->fresh()->capacity)->toBe(2);
})->with([0, -1]);
