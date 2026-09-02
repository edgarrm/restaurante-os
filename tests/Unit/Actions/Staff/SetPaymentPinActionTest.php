<?php

use App\Actions\Staff\SetPaymentPinAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
    $this->user = User::factory()->mesero()->create();
});

test('fija el pin hasheado, nunca en texto plano', function () {
    $user = (new SetPaymentPinAction)->handle($this->user, [
        'pin' => '1234',
        'pin_confirmation' => '1234',
    ]);

    expect($user->pin_hash)->not->toBeNull()
        ->and($user->pin_hash)->not->toBe('1234')
        ->and(Hash::check('1234', $user->pin_hash))->toBeTrue();
});

test('confirmación que no coincide lanza excepción de validación y no guarda nada', function () {
    expect(fn () => (new SetPaymentPinAction)->handle($this->user, [
        'pin' => '1234',
        'pin_confirmation' => '9999',
    ]))->toThrow(ValidationException::class);

    expect($this->user->fresh()->pin_hash)->toBeNull();
});

test('un pin que no son 4 dígitos lanza excepción de validación', function (string $pin) {
    expect(fn () => (new SetPaymentPinAction)->handle($this->user, [
        'pin' => $pin,
        'pin_confirmation' => $pin,
    ]))->toThrow(ValidationException::class);
})->with([
    'muy corto' => '12',
    'muy largo' => '12345',
    'no numérico' => 'abcd',
]);

test('F-04: pin_hash no es asignable vía mass assignment', function () {
    $user = User::factory()->mesero()->create();

    $user->fill(['pin_hash' => 'valor-inyectado'])->save();

    expect($user->fresh()->pin_hash)->toBeNull();

    User::query()->where('id', $user->id)->first()->update(['pin_hash' => 'otro-valor-inyectado']);

    expect($user->fresh()->pin_hash)->toBeNull();
});
