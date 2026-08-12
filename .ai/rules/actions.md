---
paths:
  - 'app/Actions/**'
---

# Actions

## Non-fillable status/flag fields: use forceFill(), not update()
`Table.status`, `MenuItem.available`, and `User.is_active`/`role` are intentionally excluded from `$fillable` (mass-assignment protection). `$model->update(['status' => ...])` on these silently no-ops — no error, attribute just doesn't change — because Eloquent's mass-assignment guard drops non-fillable keys quietly.

Always use `$model->forceFill(['status' => $value])->save()` instead, same as `OpenOrReuseOrderForTableAction`, `ToggleMenuItemAvailabilityAction`, `DeactivateStaffAccountAction`.

Trap discovered while implementing `_ai/specs/cobro.spec.md` (#7): `RequestBillAction`/`CloseOrderAction` first used `$order->table->update([...])`, which passed no error and just left `Table.status` unchanged. Only caught because a Unit test asserted `Table::fresh()->status` after the call. If you're mutating a status/flag column and a `fresh()` assertion doesn't reflect your change, check `$fillable` before assuming a relation/query bug.
