---
paths:
  - 'database/migrations/**'
---

# Migrations

## Run `php artisan migrate` against the dev SQLite before manual/browser verification
Backend sessions in this project only ever exercise migrations through Pest's `RefreshDatabase` (a separate testing DB) — `database/database.sqlite` (the one Herd/`composer run dev` actually serves) does not get new tables until someone runs `php artisan migrate` against it explicitly. Confirmed missing for `payments` (#7) and `reservations` (#8): both existed only in the test DB until migrated by hand.

Before any manual `tinker` setup or browser verification session, run `php artisan migrate --no-interaction` first, or you'll hit "no such table" for any model added in a recent backend-only session.
