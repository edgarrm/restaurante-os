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
