<?php

namespace App\Providers;

use App\Actions\Fortify\AuthorizePasskeyLogin;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Redirect post-login por rol — ver App\Http\Responses\LoginResponse
        // y _ai/specs/dashboard-del-dia.spec.md (PASO 0).
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);

        // Mismo binding, para el login por passkey — ver
        // _ai/specs/passkeys.spec.md (PASO 0, "Redirect post-login por rol").
        $this->app->singleton(PasskeyLoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasskeys();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // _ai/specs/gestion-staff.spec.md (#3), Edge Cases: una cuenta
        // desactivada (`is_active=false`, ver DeactivateStaffAccountAction)
        // no debe poder iniciar sesión. El pipeline por defecto de Fortify
        // (AttemptToAuthenticate -> Auth::attempt) no lo contempla, así que
        // se reemplaza la resolución de credenciales completa vía el punto
        // de extensión oficial del paquete. `User::where('email', ...)`
        // sigue acotado al tenant actual porque las rutas de login ya
        // inicializan tenancy (F-01, _ai/docs/threat-model.md).
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            if ($user && $user->is_active && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Sin username disponible (login por passkey es discoverable — ver
        // _ai/specs/passkeys.spec.md), throttle por IP. Mismo valor que el
        // default del paquete crudo (`throttle:6,1`), que el bridge de
        // Fortify (`configurePasskeys()`) deja en null si no se registra
        // este limiter explícitamente.
        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

    }

    /**
     * Configure passkeys (_ai/specs/passkeys.spec.md, PASO 0).
     */
    private function configurePasskeys(): void
    {
        // F-01/F-05: niega el login si el usuario resuelto es null (passkey
        // de otro tenant — ver App\Actions\Fortify\AuthorizePasskeyLogin) o
        // si la cuenta está desactivada (mismo criterio que
        // configureActions() para el login por contraseña).
        Passkeys::authorizeLoginUsing(app(AuthorizePasskeyLogin::class));
    }
}
