---
paths:
  - 'app/Actions/**'
---

# Actions

## Non-fillable status/flag fields: use forceFill(), not update()
`Table.status`, `MenuItem.available`, and `User.is_active`/`role` are intentionally excluded from `$fillable` (mass-assignment protection). `$model->update(['status' => ...])` on these silently no-ops — no error, attribute just doesn't change — because Eloquent's mass-assignment guard drops non-fillable keys quietly.

Always use `$model->forceFill(['status' => $value])->save()` instead, same as `OpenOrReuseOrderForTableAction`, `ToggleMenuItemAvailabilityAction`, `DeactivateStaffAccountAction`.

Trap discovered while implementing `_ai/specs/cobro.spec.md` (#7): `RequestBillAction`/`CloseOrderAction` first used `$order->table->update([...])`, which passed no error and just left `Table.status` unchanged. Only caught because a Unit test asserted `Table::fresh()->status` after the call. If you're mutating a status/flag column and a `fresh()` assertion doesn't reflect your change, check `$fillable` before assuming a relation/query bug.

## Check-then-claim on a shared resource: validate inside the transaction, with a lock

Any Action that (1) checks a row is still "available" (a nullable FK, a status column) and (2) then claims it for the current caller must do both steps inside the same `DB::transaction()`, with `->lockForUpdate()` on the check query, and must re-apply the same "is it still available" condition to the claiming `update()` itself, verifying the affected-row count.

Trap discovered implementing `_ai/specs/division-de-cuenta.spec.md`, "Ampliación (REDEV-29)": `AddPaymentToOrderAction::handleForItems()` first validated `OrderItem`s were unassigned (`whereNull('payment_id')->get()`) *before* opening the transaction, then claimed them with a plain `update(['payment_id' => $payment->id])` that didn't repeat the `whereNull` guard or check how many rows it actually touched. Two concurrent calls with overlapping items could both pass validation and both create a `Payment` for the same item — a financial double-count, not just a logic bug — caught by task review, not by any test (SQLite/local dev doesn't surface it). Fixed pattern, now the template for this codebase:

```php
return DB::transaction(function () use (...) {
    $rows = $model->where(...)->whereNull('claim_column')->lockForUpdate()->get();

    if ($rows->count() !== count($ids)) {
        throw ValidationException::withMessages([...]); // rolls back
    }

    // ...do the work that depends on $rows...

    $updated = $model->where(...)->whereNull('claim_column')->update(['claim_column' => $claim]);

    if ($updated !== count($ids)) {
        throw ValidationException::withMessages([...]); // rolls back
    }

    return ...;
});
```

Same criterion as `AddPaymentToOrderAction::handle()`/`CloseOrderAction`'s existing sum-based idempotency check for `Order.status === Pagada` — but that guard alone doesn't protect a narrower per-row claim like `OrderItem.payment_id`.
