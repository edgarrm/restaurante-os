<?php

use App\Actions\Staff\DeactivateStaffAccountAction;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('desactiva la cuenta sin eliminarla, preservando el historial de Order.opened_by', function () {
    $user = User::factory()->mesero()->create();
    $order = Order::factory()->create(['opened_by' => $user->id]);

    $deactivated = (new DeactivateStaffAccountAction)->handle($user);

    expect($deactivated->is_active)->toBeFalse()
        ->and(User::find($user->id))->not->toBeNull()
        ->and(User::find($user->id)->is_active)->toBeFalse()
        ->and(Order::find($order->id)->opened_by)->toBe($user->id);
});
