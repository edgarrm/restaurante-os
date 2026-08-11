---
paths:
  - database/factories/UserFactory.php
---

# Factories

## withTwoFactor() only works with make(), not create()
The users table has no two_factor_secret / two_factor_recovery_codes / two_factor_confirmed_at columns: Fortify does not auto-load its migration, and `vendor:publish --tag=fortify-migrations` was never run here. (The local database/database.sqlite still has the columns as leftovers — a fresh migrate does not.)

So `User::factory()->withTwoFactor()->create()` fails with "no such column"; only `->make()` works. Fortify's two-factor feature is also disabled in config/fortify.php ('features' => [resetPasswords()]) and User does not use TwoFactorAuthenticatable, so the 2FA tests in tests/Feature/Auth and tests/Feature/Settings skip via skipUnlessFortifyHas().

If two-factor is ever enabled, publish the Fortify migration and add the trait before relying on this factory state with create().
