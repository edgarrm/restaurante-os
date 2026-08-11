<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// F-01/F-02 (_ai/docs/threat-model.md): estas rutas ahora requieren tenancy
// inicializada.
beforeEach(function () {
    $this->tenant = actingInTenant();
});

test('confirm password screen can be rendered', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ConfirmPassword'),
    );
});

test('password confirmation requires authentication', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});
