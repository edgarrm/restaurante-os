<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Asigna role=admin. `role` no es fillable (F-04,
     * _ai/docs/threat-model.md), así que se fuerza tras crear en vez de vía
     * el array de `definition()`.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->role = Role::Admin;
            $user->save();
        });
    }

    /**
     * Asigna role=mesero explícitamente (ya es el default de la columna,
     * pero deja el estado del test autodocumentado).
     */
    public function mesero(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->role = Role::Mesero;
            $user->save();
        });
    }

    /**
     * Asigna role=cocina.
     */
    public function cocina(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->role = Role::Cocina;
            $user->save();
        });
    }

    /**
     * Marca la cuenta como desactivada (login deshabilitado, ver
     * _ai/specs/gestion-staff.spec.md). `is_active` no es fillable
     * (F-04), se fuerza tras crear igual que los demás estados de role.
     */
    public function inactive(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->is_active = false;
            $user->save();
        });
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(
                app(TwoFactorAuthenticationProvider::class)->generateSecretKey()
            ),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                Collection::times(8, fn () => RecoveryCode::generate())->toJson()
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
