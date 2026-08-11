<?php

use App\Actions\MenuItems\ToggleMenuItemAvailabilityAction;
use App\Models\MenuItem;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('alterna available sin tocar otros campos', function () {
    $menuItem = MenuItem::factory()->create(['name' => 'Tacos', 'category' => 'Platos fuertes', 'price' => 50.00]);
    expect($menuItem->available)->toBeTrue();

    $updated = (new ToggleMenuItemAvailabilityAction)->handle($menuItem);

    expect($updated->available)->toBeFalse()
        ->and($updated->name)->toBe('Tacos')
        ->and($updated->category)->toBe('Platos fuertes')
        ->and((float) $updated->price)->toBe(50.00);

    $updated = (new ToggleMenuItemAvailabilityAction)->handle($updated);

    expect($updated->available)->toBeTrue();
});
