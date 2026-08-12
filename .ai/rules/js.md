---
paths:
  - 'resources/js/**'
---

# Js

## Regenerate Wayfinder with --with-form after backend-only sessions
Backend-only sessions (this project's TDD-first convention, see _ai/CONTEXT.md) add routes/controllers without ever running `php artisan wayfinder:generate`, since no frontend code needs them yet. When a later frontend session starts, `resources/js/routes/*` and `resources/js/actions/*` won't exist for those controllers at all.

Always run `php artisan wayfinder:generate --with-form --no-interaction` (not without `--with-form`) — the project's `vite.config.ts` has `wayfinder({ formVariants: true })`, and several starter-kit pages (auth/*, settings/*) already call `.form()` on generated routes. Regenerating without `--with-form` silently drops those and breaks `npm run types:check` across unrelated pre-existing pages, not just the ones you're adding.
