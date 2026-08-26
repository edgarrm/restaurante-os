---
paths:
  - 'tests/Feature/**'
---

# Feature

## Testing Inertia GET routes for specs without a Vue page yet ("backend only")
`resources/views/app.blade.php` does `@vite([..., "resources/js/pages/{$page['component']}.vue"])` on every full-page load, so a plain `$this->get(route(...))` against an Inertia route 500s with `ViteException` if that page's `.vue` file isn't built yet — happens for any "backend only" spec (see `_ai/CONTEXT.md` convention of shipping backend before the Vue screen).

Fix: simulate Inertia's XHR navigation instead of a full page load, using `inertiaXhrHeaders()` (tests/Pest.php). It sends `X-Inertia: true` + a matching `X-Inertia-Version` (the Vite manifest hash — required or Inertia responds 409, asset-version conflict) so the controller returns the Inertia JSON payload directly, skipping the blade/`@vite` render entirely.

That JSON response is NOT a "valid Inertia response" to `Inertia\Testing\AssertableInertia` (`assertInertia()` calls `assertViewHas('page')`, which only exists on a real Blade-rendered response) — assert on it directly instead: `$response->assertJsonPath('component', 'menu/Index')` and `$response->json('props.someKey')`.

Separately: a POST/PATCH with invalid data against an Inertia route redirects 302 with flashed session errors, not 422 — that's Inertia's normal validation-error UX, not a bug. Use `postJson()`/`patchJson()` (Accept: application/json) instead of `post()`/`patch()` when a test needs to assert `422`.

Precedent: `tests/Feature/GestionMenuTest.php` (_ai/specs/gestion-menu.spec.md, #2).

## POST /mesas/{table}/cobro, .../pagos, .../pagos/por-items now require a verified PIN (F-07)
Since `_ai/specs/bloqueo-tablet-pin.spec.md`, these 3 endpoints run the `payment-pin` middleware (`EnsurePaymentPinVerified`) before the controller: a mesero/admin with no `pin_hash` gets `ValidationException(['pin_not_set' => ...])`, and one with a `pin_hash` but no fresh `pin_verified_at` in the session gets `ValidationException(['pin' => ...])`. Any Feature test that POSTs to these routes and isn't specifically about the PIN gate needs to simulate an already-configured, already-verified user, or it 422s with no `Payment` created — same failure mode as forgetting `role:admin,mesero`.

Fix, same pattern as `tests/Feature/CobroTest.php`/`DivisionDeCuentaTest.php`: create the mesero with `User::factory()->...->withPaymentPin()->create()` (factory state, `database/factories/UserFactory.php`) and call `$this->withSession(['pin_verified_at' => now()->timestamp])` once in `beforeEach` before any request. `withSession()` persists for every subsequent `$this->get()/post()` call in the same test method — no need to repeat it per test or per `actingAs()` swap.
