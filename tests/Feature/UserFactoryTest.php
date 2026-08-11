<?php

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('the two factor state configures two factor authentication', function () {
    $user = User::factory()->withTwoFactor()->make();

    $encrypter = Fortify::currentEncrypter();

    expect($encrypter->decrypt((string) $user->two_factor_secret))->not->toBeEmpty()
        ->and(json_decode($encrypter->decrypt((string) $user->two_factor_recovery_codes), true))
        ->toBeArray()
        ->toHaveCount(8)
        ->and($user->two_factor_confirmed_at)->not->toBeNull();
});

test('the two factor state generates a secret the fortify provider can verify', function () {
    $user = User::factory()->withTwoFactor()->make();

    $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

    expect(app(TwoFactorAuthenticationProvider::class)->verify($secret, app(Google2FA::class)->getCurrentOtp($secret)))
        ->toBeTrue();
});

test('users are created without two factor authentication by default', function () {
    $user = User::factory()->make();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});
