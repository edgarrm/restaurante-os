<?php

use App\Actions\MenuItems\CreateMenuItemAction;
use App\Models\MenuItem;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('crea un platillo con available=true por defecto', function () {
    $menuItem = (new CreateMenuItemAction)->handle([
        'name' => 'Tacos al pastor',
        'category' => 'Platos fuertes',
        'price' => 85.50,
    ]);

    expect($menuItem->name)->toBe('Tacos al pastor')
        ->and($menuItem->category)->toBe('Platos fuertes')
        ->and((float) $menuItem->price)->toBe(85.50)
        ->and($menuItem->available)->toBeTrue();
});

test('precio menor o igual a 0 lanza error de validación y no crea el platillo', function (float $price) {
    expect(fn () => (new CreateMenuItemAction)->handle([
        'name' => 'Tacos al pastor',
        'category' => 'Platos fuertes',
        'price' => $price,
    ]))->toThrow(ValidationException::class);

    expect(MenuItem::count())->toBe(0);
})->with([0, -10]);
