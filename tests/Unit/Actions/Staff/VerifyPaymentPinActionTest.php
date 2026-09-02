<?php

use App\Actions\Staff\SetPaymentPinAction;
use App\Actions\Staff\VerifyPaymentPinAction;
use App\Exceptions\Staff\TooManyPinAttemptsException;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
    $this->user = User::factory()->mesero()->create();
    (new SetPaymentPinAction)->handle($this->user, ['pin' => '1234', 'pin_confirmation' => '1234']);
});

test('pin correcto devuelve true', function () {
    $verified = (new VerifyPaymentPinAction)->handle($this->user->fresh(), '1234');

    expect($verified)->toBeTrue();
});

test('pin incorrecto devuelve false, sin lanzar una excepción distinta', function () {
    $verified = (new VerifyPaymentPinAction)->handle($this->user->fresh(), '0000');

    expect($verified)->toBeFalse();
});

test('usuario sin pin_hash devuelve false, nunca revela que el pin no existe', function () {
    $userSinPin = User::factory()->mesero()->create();

    $verified = (new VerifyPaymentPinAction)->handle($userSinPin, '1234');

    expect($verified)->toBeFalse();
});

test('5 intentos fallidos en un minuto bloquean el sexto con TooManyPinAttemptsException', function () {
    $action = new VerifyPaymentPinAction;
    $user = $this->user->fresh();

    for ($i = 0; $i < 5; $i++) {
        expect($action->handle($user, '0000'))->toBeFalse();
    }

    expect(fn () => $action->handle($user, '0000'))->toThrow(TooManyPinAttemptsException::class);
    // Ni siquiera con el PIN correcto: el lockout aplica a todo intento
    // hasta que expire la ventana, mismo criterio que el lockout de login.
    expect(fn () => $action->handle($user, '1234'))->toThrow(TooManyPinAttemptsException::class);
});

test('un intento correcto limpia el rate limiter previo', function () {
    $action = new VerifyPaymentPinAction;
    $user = $this->user->fresh();

    $action->handle($user, '0000');
    $action->handle($user, '0000');

    expect($action->handle($user, '1234'))->toBeTrue();

    // El contador se limpió: puede volver a fallar varias veces sin
    // toparse de inmediato con el lockout de los intentos previos.
    expect($action->handle($user, '0000'))->toBeFalse();
});

test('F-05: el pin correcto de un usuario de otro tenant no verifica contra el usuario del tenant actual', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'tenant-b-pin.test']);
    $userTenantB = User::factory()->for($tenantB, 'tenant')->mesero()->create();
    (new SetPaymentPinAction)->handle($userTenantB, ['pin' => '5678', 'pin_confirmation' => '5678']);

    // El usuario del tenant actual (this->user) intenta "verificar" con el
    // PIN que en realidad pertenece al usuario del tenant B — nunca debe
    // pasar, porque la Action solo compara contra el hash del propio
    // usuario pasado como argumento, jamás busca por PIN.
    $verified = (new VerifyPaymentPinAction)->handle($this->user->fresh(), '5678');

    expect($verified)->toBeFalse();
});
