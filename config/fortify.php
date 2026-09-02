<?php

use App\Http\Middleware\ScopePasskeysToTenantDomain;
use Laravel\Fortify\Features;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    | F-01 (_ai/docs/threat-model.md): sin InitializeTenancyByDomain, /login
    | resuelve `User::where('email', ...)` contra TODOS los tenants (el
    | Global Scope de TenantScope no filtra si tenancy no está inicializada),
    | permitiendo que un usuario del restaurante B se autentique en el
    | subdominio del restaurante A. PreventAccessFromCentralDomains bloquea
    | además que estas rutas se sirvan desde el dominio central. ScopeSessions
    | (F-02) ata la sesión resultante a ese tenant. Mismo stack que el grupo
    | de `routes/tenant.php` — decisión registrada en decision-log.md.
    |
    |
    | ScopePasskeysToTenantDomain (_ai/specs/passkeys.spec.md, PASO 0,
    | F-01/F-05 para passkeys): Fortify registra las rutas de
    | laravel/passkeys dentro de este MISMO grupo (ver
    | Laravel\Fortify\FortifyServiceProvider::configurePasskeys(), que
    | ademas bindea config('passkeys.middleware') a este mismo array) - asi
    | que este middleware ata el Relying Party ID de WebAuthn al subdominio
    | real de la peticion en vez del config('app.url') global, para las
    | rutas de passkeys. Para el resto de rutas de este grupo (login,
    | logout, password reset, 2FA) es un no-op barato (dos claves de config
    | que nadie mas lee).
    |
    */

    'middleware' => [
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
        ScopeSessions::class,
        ScopePasskeysToTenantDomain::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        // Sin esto, `configurePasskeys()` deja `passkeys.throttle` en null
        // (sin limitador) — confirmado en runtime. El paquete crudo trae
        // `throttle:6,1` como default; se restaura el mismo valor vía este
        // limiter (_ai/specs/passkeys.spec.md).
        'passkeys' => 'passkeys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features, or you can even remove all of these if you need to.
    |
    */

    'features' => [
        Features::resetPasswords(),
        // Login sin contraseña, adicional al de contraseña
        // (_ai/specs/passkeys.spec.md). `confirmPassword` (default true) es
        // lo que gatea `/user/passkeys/*` con `password.confirm` — mismo
        // criterio que "revocar con tu contraseña normal" del spec.
        Features::passkeys(),
    ],

];
