# División de Cuenta por Ítems (REDEV-29) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar un mecanismo de split de cuenta por ítems que convive con el split por monto libre ya implementado: el mesero selecciona `OrderItem`s de la cuenta, el sistema calcula el monto en el servidor y registra un pago igual que el flujo existente.

**Architecture:** `order_items.payment_id` (FK nullable a `payments.id`) marca qué ítems ya fueron cobrados por un `Payment` específico. `AddPaymentToOrderAction` gana un método hermano `handleForItems()` que calcula el monto sumando los ítems validados, crea el `Payment` y reutiliza la misma lógica de cierre que `handle()` (extraída a helpers privados compartidos). Ruta hermana nueva (`cobro.pagos.porItems`) + método nuevo en `PaymentController`. `mesas/Cobro.vue` gana un toggle "Por monto" / "Por ítems".

**Tech Stack:** Laravel 13 + Pest 5 (backend), Vue 3.5 + Inertia v3 + Wayfinder (frontend), SQLite dev/test.

**Spec:** `_ai/specs/division-de-cuenta.spec.md`, sección `## Ampliación (REDEV-29): Split por Ítems` — el plan argumenta desde ahí; cada ejecutor debe leer esa sección antes de su tarea.

## Global Constraints

- PHP: llaves siempre (aunque sea una línea), tipos de retorno explícitos, constructor property promotion, PHPDoc con array shapes, enums en TitleCase.
- Errores de dominio: `ValidationException::withMessages([...])`, **nunca** `abort(422, ...)`.
- No usar `DB::table()` ni queries crudas en código de dominio (evaden `TenantScope`).
- `Table.status` y otros campos no-fillable se mutan con `forceFill()->save()`, nunca `update()`.
- No modificar `tests/Feature/CobroTest.php` ni `tests/Unit/Actions/Orders/CloseOrderActionTest.php` — deben seguir en verde sin tocarlos.
- No modificar la firma ni el comportamiento de `AddPaymentToOrderAction::handle()` — solo se le agrega un método hermano.
- Rutas: Wayfinder (`@/actions`, `@/routes`) en el frontend, nunca URLs hardcodeadas.
- Vue: un solo elemento raíz por componente.
- Tras cualquier cambio PHP: `vendor/bin/pint --dirty --format agent` antes de dar la tarea por terminada.
- Tras cualquier cambio de rutas/controllers consumidos por el frontend: `php artisan wayfinder:generate --with-form --no-interaction` (con `--with-form`, nunca sin — ver `.ai/rules/js.md`).

---

## Task 0: Preparar el entorno del worktree

Este worktree se creó sin `vendor/` ni `.env` (confirmado con `ls vendor` / `ls .env`). Sigue el mismo patrón ya documentado en `_ai/docs/decision-log.md` (entradas de Reservas y División de Cuenta) para sesiones anteriores en worktrees nuevos de este proyecto.

**Files:** ninguno del repo — solo estado local del worktree (`.env`, `vendor/`, symlink de `database.sqlite`).

- [ ] **Step 1: Instalar dependencias PHP**

```bash
composer install --no-interaction
```

Expected: termina sin errores (PHP 8.5.8 ya activo en este shell — confirmado con `php -v`).

- [ ] **Step 2: Copiar `.env` de main y symlinkear la DB compartida**

```bash
cp ~/Herd/restaurante-os/.env .env
ln -sf ~/Herd/restaurante-os/database/database.sqlite database/database.sqlite
```

Expected: `.env` y el symlink existen (`ls -la .env database/database.sqlite`). Mismo patrón que sesiones anteriores — no se crea una DB nueva.

- [ ] **Step 3: Verificar que `pnpm-workspace.yaml` no esté corrupto**

```bash
git status --porcelain pnpm-workspace.yaml
cat pnpm-workspace.yaml
```

Expected: sin cambios locales, y sin ningún bloque `allowBuilds` con placeholder `"set this to true or false"`. Si aparece corrupto (trampa ya documentada en `decision-log.md`), revertirlo: `git checkout -- pnpm-workspace.yaml` y borrar cualquier `pnpm-lock.yaml` suelto y no rastreado.

- [ ] **Step 4: Migrar la DB de dev (no solo la de test)**

```bash
php artisan migrate --no-interaction
```

Expected: sin migraciones pendientes que fallen (corre contra el sqlite symlinkeado a main — necesario para la verificación manual del Task 4, ver `.ai/rules/migrations.md`).

- [ ] **Step 5: Confirmar que la suite ya pasa antes de tocar código**

```bash
php artisan test --compact
```

Expected: PASS (verde) — esta es la línea base antes de empezar. Si algo ya falla aquí, detente y repórtalo antes de continuar (no es parte de este trabajo).

---

## Task 1: Migración + relaciones + `AddPaymentToOrderAction::handleForItems()`

**Files:**
- Create: `database/migrations/2026_08_12_220000_add_payment_id_to_order_items_table.php`
- Modify: `app/Models/OrderItem.php`
- Modify: `app/Models/Payment.php`
- Modify: `app/Actions/Orders/AddPaymentToOrderAction.php`
- Test: `tests/Unit/Actions/Orders/AddPaymentToOrderActionTest.php` (extiende el archivo existente — no tocar los 7 tests que ya tiene)

**Interfaces:**
- Produces: `AddPaymentToOrderAction::handleForItems(Order $order, array $itemIds, PaymentMethod $method, User $collectedBy): Order` — usado por Task 2. Lanza `Illuminate\Validation\ValidationException` si algún `$itemIds` no pertenece a `$order` o ya tiene `payment_id` asignado.
- Produces: `OrderItem::payment(): BelongsTo` y `Payment::items(): HasMany` — usados por Task 3 (frontend, vía props serializadas) y por los tests de Task 2.

- [ ] **Step 1: Escribir los tests unitarios nuevos (deben fallar)**

Añadir al final de `tests/Unit/Actions/Orders/AddPaymentToOrderActionTest.php` (después del último `test(...)` existente, mismo archivo, mismos imports — agregar `use Illuminate\Validation\ValidationException;` al bloque de `use` del inicio del archivo):

```php
test('handleForItems: pago de grupo que no cubre el saldo no cierra la orden ni libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $result = (new AddPaymentToOrderAction)->handleForItems($order, [$item->id], PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::PorCobrar)
        ->and($order->table->fresh()->status)->toBe(TableStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(30.00)
        ->and($item->fresh()->payment_id)->toBe(Payment::sole()->id);
});

test('handleForItems: pago de grupo que cubre el total cierra la orden y libera la mesa', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemIds = $order->items()->pluck('id')->all();

    $result = (new AddPaymentToOrderAction)->handleForItems($order, $itemIds, PaymentMethod::Tarjeta, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and($order->table->fresh()->status)->toBe(TableStatus::Libre)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(130.00);
});

test('handleForItems: ítem ya asignado a un pago previo lanza ValidationException y no crea un segundo Payment', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $item = $order->items()->where('unit_price', 30.00)->sole();
    (new AddPaymentToOrderAction)->handleForItems($order, [$item->id], PaymentMethod::Efectivo, $collectedBy);

    expect(fn () => (new AddPaymentToOrderAction)->handleForItems($order->fresh(), [$item->id], PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(1);
});

test('handleForItems: ítem que no pertenece a la orden lanza ValidationException', function () {
    $order = ordenParaDividir();
    $otraOrden = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemAjeno = $otraOrden->items()->first();

    expect(fn () => (new AddPaymentToOrderAction)->handleForItems($order, [$itemAjeno->id], PaymentMethod::Efectivo, $collectedBy))
        ->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(0);
});

test('handleForItems: orden ya pagada no crea un segundo Payment (idempotente)', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $order->update(['status' => OrderStatus::Pagada]);
    Payment::factory()->for($order)->create(['collected_by' => $collectedBy->id, 'amount' => 130.00]);
    $itemId = $order->items()->first()->id;

    $result = (new AddPaymentToOrderAction)->handleForItems($order, [$itemId], PaymentMethod::Efectivo, $collectedBy);

    expect($result->status)->toBe(OrderStatus::Pagada)
        ->and(Payment::count())->toBe(1);
});

test('F-03: handleForItems registra collected_by igual al usuario autenticado pasado a la Action', function () {
    $order = ordenParaDividir();
    $collectedBy = User::factory()->create();
    $itemId = $order->items()->first()->id;

    (new AddPaymentToOrderAction)->handleForItems($order, [$itemId], PaymentMethod::Efectivo, $collectedBy);

    expect(Payment::sole()->collected_by)->toBe($collectedBy->id);
});
```

- [ ] **Step 2: Correr los tests para confirmar que fallan**

```bash
php artisan test --compact --filter=AddPaymentToOrderActionTest
```

Expected: FAIL — `Call to undefined method App\Actions\Orders\AddPaymentToOrderAction::handleForItems()` (los 6 tests nuevos rojos; los 7 existentes siguen en verde).

- [ ] **Step 3: Crear la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Split por ítems (REDEV-29, _ai/specs/division-de-cuenta.spec.md,
        // "Ampliación"): un OrderItem asignado a un Payment queda
        // "cobrado" por ese grupo. Nullable a propósito — un ítem sin
        // asignar no bloquea el cierre de la cuenta (decisión de
        // producto, PASO 0 de REDEV-29): el cierre sigue siendo 100% por
        // monto acumulado.
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
```

Guardar en `database/migrations/2026_08_12_220000_add_payment_id_to_order_items_table.php`.

- [ ] **Step 4: Agregar las relaciones a los modelos**

En `app/Models/OrderItem.php`, agregar `payment_id` al bloque de `@property` (después de `@property OrderItemStatus $status`):

```php
 * @property int|null $payment_id
```

Y agregar el método de relación (después de `menuItem()`, antes del cierre de la clase):

```php
    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
```

En `app/Models/Payment.php`, agregar el import `use Illuminate\Database\Eloquent\Relations\HasMany;` junto al de `BelongsTo`, y el método de relación (después de `collector()`, antes del cierre de la clase):

```php
    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
```

No se toca `#[Fillable(...)]` de `OrderItem` en ninguno de los dos modelos — la asignación de `payment_id` se hace vía `update()` de query builder sobre la relación (`$order->items()->whereIn(...)->update([...])`), que no pasa por mass assignment de Eloquent.

- [ ] **Step 5: Correr la migración**

```bash
php artisan migrate --no-interaction
```

Expected: aplica la nueva migración sin errores (contra el sqlite de test vía RefreshDatabase se aplica automáticamente en el siguiente paso; esto además la deja aplicada en la DB de dev symlinkeada, necesaria para el Task 4).

- [ ] **Step 6: Implementar `handleForItems()` — reemplazar el archivo completo**

Reemplazar el contenido completo de `app/Actions/Orders/AddPaymentToOrderAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddPaymentToOrderAction
{
    /**
     * Registra un pago — parcial o que completa el total — contra una orden
     * (ver _ai/specs/division-de-cuenta.spec.md, US-3.2). A diferencia de
     * `CloseOrderAction`, nunca rechaza por monto insuficiente: crea el
     * `Payment` y solo cierra la orden + libera la mesa cuando la suma de
     * todos sus pagos alcanza o supera `Order::total()`.
     *
     * Idempotente: si la orden ya está `pagada`, no crea un segundo
     * `Payment` (mismo criterio que `CloseOrderAction`).
     *
     * F-03 (_ai/docs/threat-model.md): `collected_by` viene siempre de
     * `$collectedBy` — no hay forma de que el llamador inyecte otro valor.
     */
    public function handle(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $this->createPayment($order, $amount, $method, $collectedBy);

        return $this->closeIfCovered($order);
    }

    /**
     * Registra un pago cuyo monto se calcula sumando un grupo de
     * `OrderItem`s seleccionados (REDEV-29, split por ítems — ver
     * _ai/specs/division-de-cuenta.spec.md, "Ampliación"). El monto nunca
     * viene del cliente: se calcula 100% en el servidor a partir de los
     * ítems validados. Reutiliza `createPayment()`/`closeIfCovered()`, los
     * mismos helpers que `handle()`, para no duplicar la lógica de cierre.
     *
     * Idempotente: si la orden ya está `pagada`, no crea un segundo
     * `Payment`.
     *
     * F-03: mismo criterio que `handle()` — `collected_by` viene siempre de
     * `$collectedBy`.
     *
     * @param  array<int, int>  $itemIds
     *
     * @throws ValidationException si algún ítem no pertenece a la orden o
     *                              ya fue asignado a otro pago
     */
    public function handleForItems(Order $order, array $itemIds, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $itemIds = array_values(array_unique($itemIds));

        $items = $order->items()->whereIn('id', $itemIds)->whereNull('payment_id')->get();

        if ($items->count() !== count($itemIds)) {
            throw ValidationException::withMessages([
                'item_ids' => 'Uno o más ítems ya fueron cobrados en otro pago.',
            ]);
        }

        $amount = (float) $items->sum(
            fn (OrderItem $item): float => $item->quantity * (float) $item->unit_price
        );

        return DB::transaction(function () use ($order, $amount, $method, $collectedBy, $itemIds) {
            $payment = $this->createPayment($order, $amount, $method, $collectedBy);

            $order->items()->whereIn('id', $itemIds)->update(['payment_id' => $payment->id]);

            return $this->closeIfCovered($order);
        });
    }

    private function createPayment(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Payment
    {
        return $order->payments()->create([
            'collected_by' => $collectedBy->id,
            'amount' => $amount,
            'method' => $method,
            'paid_at' => now(),
        ]);
    }

    private function closeIfCovered(Order $order): Order
    {
        $paid = (float) $order->payments()->sum('amount');

        if ($paid >= $order->total()) {
            $order->update(['status' => OrderStatus::Pagada, 'closed_at' => now()]);

            // 'status' no es fillable en Table (ver .ai/rules/actions.md),
            // así que se cambia con forceFill en vez de update().
            $order->table->forceFill(['status' => TableStatus::Libre])->save();
        }

        return $order->fresh();
    }
}
```

- [ ] **Step 7: Correr los tests para confirmar que pasan**

```bash
php artisan test --compact --filter=AddPaymentToOrderActionTest
```

Expected: PASS — los 7 tests originales + los 6 nuevos, 13/13.

- [ ] **Step 8: Confirmar que `CloseOrderActionTest` sigue en verde sin tocarlo**

```bash
php artisan test --compact --filter=CloseOrderActionTest
```

Expected: PASS — mismos tests que antes, sin modificar el archivo.

- [ ] **Step 9: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_12_220000_add_payment_id_to_order_items_table.php \
        app/Models/OrderItem.php app/Models/Payment.php \
        app/Actions/Orders/AddPaymentToOrderAction.php \
        tests/Unit/Actions/Orders/AddPaymentToOrderActionTest.php
git commit -m "feat(cobro): AddPaymentToOrderAction::handleForItems para split por ítems (REDEV-29)"
```

---

## Task 2: Ruta + `PaymentController::addPaymentByItems()`

**Files:**
- Modify: `routes/tenant.php`
- Modify: `app/Http/Controllers/PaymentController.php`
- Test: `tests/Feature/DivisionDeCuentaTest.php` (extiende el archivo existente — no tocar los tests que ya tiene, ni `tests/Feature/CobroTest.php`)

**Interfaces:**
- Consumes: `AddPaymentToOrderAction::handleForItems()` de Task 1.
- Produces: ruta nombrada `cobro.pagos.porItems` (`POST /mesas/{table}/cobro/pagos/por-items`) — usada por Task 3 vía Wayfinder (`porItems` de `@/routes/cobro/pagos`).

- [ ] **Step 1: Escribir los tests de integración nuevos (deben fallar)**

Añadir al final de `tests/Feature/DivisionDeCuentaTest.php` (después del último `test(...)` existente, mismos imports):

```php
test('POST cobro.pagos.porItems con ítems válidos y monto parcial registra el pago y no cierra la cuenta', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertRedirect(route('cobro.show', $table));
    expect($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and(Payment::count())->toBe(1)
        ->and((float) Payment::sole()->amount)->toBe(30.00)
        ->and($item->fresh()->payment_id)->toBe(Payment::sole()->id);
});

test('POST cobro.pagos.porItems con ítems que completan el saldo cierra la cuenta y libera la mesa', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemIds = $order->items()->pluck('id')->all();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => $itemIds, 'method' => PaymentMethod::Tarjeta->value]);

    $response->assertRedirect(route('mesas.index'));
    expect($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($table->fresh()->status)->toBe(TableStatus::Libre);
});

test('POST cobro.pagos.porItems con item_ids vacío devuelve 422', function () {
    $table = mesaPorCobrarDe130();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

test('POST cobro.pagos.porItems con un ítem ya asignado a otro pago devuelve 422', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();
    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Tarjeta->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(1);
});

test('POST cobro.pagos.porItems con un ítem que no pertenece a la orden devuelve 422', function () {
    $table = mesaPorCobrarDe130();
    $otraMesa = mesaPorCobrarDe130();
    $itemAjeno = $otraMesa->orders()->sole()->items()->first();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('cobro.pagos.porItems', $table), ['item_ids' => [$itemAjeno->id], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

test('usuario con role=cocina accede a cobro.pagos.porItems → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemId = $order->items()->first()->id;

    $response = $this->actingAs($cocina)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$itemId], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertForbidden();
});

test('F-03: un collected_by enviado en el body de cobro.pagos.porItems es ignorado', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $itemId = $order->items()->first()->id;
    $otroUsuario = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $this->actingAs($this->mesero)->post(route('cobro.pagos.porItems', $table), [
        'item_ids' => [$itemId],
        'method' => PaymentMethod::Efectivo->value,
        'collected_by' => $otroUsuario->id,
    ]);

    expect(Payment::sole()->collected_by)->toBe($this->mesero->id);
});

test('F-05: mesero del restaurante A pide cobro.pagos.porItems de la mesa de otro restaurante → 404', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $tableB), ['item_ids' => [1], 'method' => PaymentMethod::Efectivo->value]);

    $response->assertNotFound();
});

test('GET /mesas/{table}/cobro tras un pago por ítems muestra el payment_id asignado en las props', function () {
    $table = mesaPorCobrarDe130();
    $order = $table->orders()->sole();
    $item = $order->items()->where('unit_price', 30.00)->sole();

    $this->actingAs($this->mesero)
        ->post(route('cobro.pagos.porItems', $table), ['item_ids' => [$item->id], 'method' => PaymentMethod::Efectivo->value]);

    $response = $this->actingAs($this->mesero)
        ->get(route('cobro.show', $table), inertiaXhrHeaders());

    $response->assertOk();
    $items = collect($response->json('props.order.items'));
    $paidItem = $items->firstWhere('id', $item->id);

    expect($paidItem['payment_id'])->not->toBeNull();
});
```

- [ ] **Step 2: Correr los tests para confirmar que fallan**

```bash
php artisan test --compact --filter=DivisionDeCuentaTest
```

Expected: FAIL — `Route [cobro.pagos.porItems] not defined` (los 9 tests nuevos rojos; los existentes de este archivo siguen en verde).

- [ ] **Step 3: Agregar la ruta**

En `routes/tenant.php`, dentro del grupo `cobro.` (después de la línea `Route::post('/pagos', [PaymentController::class, 'addPayment'])->name('pagos.store');`):

```php
            // Split por ítems (REDEV-29, _ai/specs/division-de-cuenta.spec.md,
            // "Ampliación"): el monto se calcula en el servidor a partir de
            // los OrderItems seleccionados, nunca del cliente. Nombre de ruta
            // en camelCase (`porItems`) para que Wayfinder genere un
            // identificador JS válido.
            Route::post('/pagos/por-items', [PaymentController::class, 'addPaymentByItems'])->name('pagos.porItems');
```

- [ ] **Step 4: Agregar el método al controller**

En `app/Http/Controllers/PaymentController.php`, agregar después de `addPayment()` (antes del cierre de la clase):

```php
    /**
     * Registra un pago cuyo monto se calcula sumando un grupo de
     * `OrderItem`s seleccionados (REDEV-29, split por ítems —
     * _ai/specs/division-de-cuenta.spec.md, "Ampliación"). A diferencia de
     * `addPayment`, no recibe `amount`: el monto nunca viene del cliente.
     *
     * F-03: mismo criterio que `close`/`addPayment` — `collected_by`
     * siempre `$request->user()`.
     */
    public function addPaymentByItems(Request $request, Table $table, AddPaymentToOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);

        $order = $table->orders()
            ->whereIn('status', [
                OrderStatus::Abierta,
                OrderStatus::EnviadaCocina,
                OrderStatus::Lista,
                OrderStatus::PorCobrar,
                OrderStatus::Pagada,
            ])
            ->latest()
            ->firstOrFail();

        $order = $action->handleForItems($order, $data['item_ids'], PaymentMethod::from($data['method']), $request->user());

        return $order->status === OrderStatus::Pagada
            ? to_route('mesas.index')
            : to_route('cobro.show', $table);
    }
```

No se necesitan imports nuevos — `AddPaymentToOrderAction`, `Table`, `RedirectResponse`, `Request`, `Rule`, `PaymentMethod`, `OrderStatus` ya están importados en el archivo.

- [ ] **Step 5: Cargar la relación `payments.items` en `show()` para que el frontend (Task 3) tenga los datos**

En `app/Http/Controllers/PaymentController.php`, método `show()`, cambiar:

```php
            'order' => $order->load(['items.menuItem', 'payments.collector']),
```

por:

```php
            'order' => $order->load(['items.menuItem', 'payments.collector', 'payments.items.menuItem']),
```

- [ ] **Step 6: Correr los tests para confirmar que pasan**

```bash
php artisan test --compact --filter=DivisionDeCuentaTest
```

Expected: PASS — los tests existentes de este archivo + los 9 nuevos.

- [ ] **Step 7: Confirmar que `CobroTest` sigue en verde sin tocarlo**

```bash
php artisan test --compact --filter=CobroTest
```

Expected: PASS — mismos tests que antes, sin modificar el archivo.

- [ ] **Step 8: Formatear y commitear**

```bash
vendor/bin/pint --dirty --format agent
git add routes/tenant.php app/Http/Controllers/PaymentController.php tests/Feature/DivisionDeCuentaTest.php
git commit -m "feat(cobro): ruta y controller para split por ítems (REDEV-29)"
```

---

## Task 3: Wayfinder + tipos TS + UI en `mesas/Cobro.vue`

**Files:**
- Modify: `resources/js/types/models.ts`
- Modify: `resources/js/pages/mesas/Cobro.vue`
- Generated (no editar a mano): `resources/js/routes/cobro/pagos/index.ts` (vía Wayfinder)

**Interfaces:**
- Consumes: ruta `cobro.pagos.porItems` de Task 2, exportada por Wayfinder como `porItems` desde `@/routes/cobro/pagos`.

- [ ] **Step 1: Regenerar Wayfinder**

```bash
php artisan wayfinder:generate --with-form --no-interaction
```

Expected: sin errores; `resources/js/routes/cobro/pagos/index.ts` ahora exporta tanto `store` (ya existía) como `porItems` (nuevo).

- [ ] **Step 2: Actualizar los tipos TS**

En `resources/js/types/models.ts`, en `interface OrderItem`, agregar el campo (después de `status: OrderItemStatus;`):

```typescript
    payment_id: number | null;
```

En `interface Payment`, agregar el campo opcional (después de `collector?: { id: number; name: string };`):

```typescript
    items?: OrderItem[];
```

- [ ] **Step 3: Correr `types:check` para confirmar que el tipo nuevo no rompe nada existente**

```bash
npm run types:check
```

Expected: PASS, o solo el error esperado en `Cobro.vue` de que `porItems`/checkbox aún no se usan (se resuelve en el siguiente paso).

- [ ] **Step 4: Agregar el toggle "Por monto"/"Por ítems" y la selección de ítems a `Cobro.vue`**

En `resources/js/pages/mesas/Cobro.vue`, sección `<script setup>`:

Agregar imports (junto a los existentes):

```typescript
import { Checkbox } from '@/components/ui/checkbox';
```

y cambiar el import de rutas de pagos:

```typescript
import { store as addPaymentRoute } from '@/routes/cobro/pagos';
```

por:

```typescript
import { porItems as addPaymentByItemsRoute, store as addPaymentRoute } from '@/routes/cobro/pagos';
```

Agregar, después de la declaración de `const method = ref<PaymentMethod>('efectivo');`:

```typescript
// Split por ítems (REDEV-29, _ai/specs/division-de-cuenta.spec.md,
// "Ampliación"): segundo modo que convive con el de monto libre, no lo
// reemplaza.
const mode = ref<'monto' | 'items'>('monto');

const itemsSinAsignar = computed(() => cuentaLines.value.filter((line) => line.orderItem.payment_id === null));

const selectedItemIds = ref<number[]>([]);

function toggleItem(itemId: number) {
    const index = selectedItemIds.value.indexOf(itemId);

    if (index === -1) {
        selectedItemIds.value.push(itemId);
    } else {
        selectedItemIds.value.splice(index, 1);
    }
}

const subtotalSeleccionado = computed(() =>
    itemsSinAsignar.value
        .filter((line) => selectedItemIds.value.includes(line.orderItem.id))
        .reduce((sum, line) => sum + line.subtotal, 0),
);

function confirmPaymentByItems() {
    if (selectedItemIds.value.length === 0 || processing.value) {
        return;
    }

    processing.value = true;

    router.post(
        addPaymentByItemsRoute.url(table.id),
        { item_ids: selectedItemIds.value, method: method.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedItemIds.value = [];
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
```

(`processing` ya existe más abajo en el archivo — como `ref`s de Vue no dependen del orden de declaración dentro de `<script setup>` para su uso en funciones definidas antes pero llamadas después del montaje, esto es seguro; si el linter de orden de declaración se queja, mover el bloque nuevo a después de `const processing = ref(false);` en su lugar.)

En la sección `<template>`, dentro de la Card "Cobrar" (`<CardContent class="flex flex-col gap-4">`), agregar el toggle de modo **antes** del bloque `<div class="flex flex-col gap-2"><Label>Método de pago</Label>...`:

```html
                    <div class="grid grid-cols-2 gap-2">
                        <Button type="button" size="sm" :variant="mode === 'monto' ? 'default' : 'outline'" @click="mode = 'monto'">
                            Por monto
                        </Button>
                        <Button type="button" size="sm" :variant="mode === 'items' ? 'default' : 'outline'" @click="mode = 'items'">
                            Por ítems
                        </Button>
                    </div>
```

Envolver el bloque existente de método de pago + monto + cambio + botón de confirmar — en el archivo actual (previo a este task) son las líneas 236 a 277, desde `<div class="flex flex-col gap-2">` que contiene `<Label>Método de pago</Label>` hasta el `</Button>` que cierra "Confirmar pago"/"Registrar pago parcial" — en `<template v-if="mode === 'monto'">...</template>`, sin cambiar una sola línea de su contenido interno (solo agregar la etiqueta `<template v-if="mode === 'monto'">` antes y `</template>` después).

Después de ese `</template>`, agregar el panel del modo "Por ítems":

```html
                    <template v-else>
                        <div class="flex flex-col gap-2">
                            <Label>Método de pago</Label>
                            <div class="grid grid-cols-3 gap-2">
                                <Button
                                    v-for="option in methodOptions"
                                    :key="option.value"
                                    type="button"
                                    size="sm"
                                    :variant="method === option.value ? 'default' : 'outline'"
                                    @click="method = option.value"
                                >
                                    {{ option.label }}
                                </Button>
                            </div>
                        </div>

                        <div v-if="itemsSinAsignar.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                            No quedan ítems sin cobrar.
                        </div>
                        <ul v-else class="flex flex-col gap-2">
                            <li v-for="line in itemsSinAsignar" :key="line.orderItem.id" class="flex items-center gap-2">
                                <Checkbox
                                    :id="`item-${line.orderItem.id}`"
                                    :model-value="selectedItemIds.includes(line.orderItem.id)"
                                    @update:model-value="toggleItem(line.orderItem.id)"
                                />
                                <label :for="`item-${line.orderItem.id}`" class="flex flex-1 items-center justify-between gap-2 text-sm">
                                    <span class="text-foreground">
                                        {{ line.orderItem.menu_item?.name ?? `Platillo #${line.orderItem.menu_item_id}` }} × {{ line.orderItem.quantity }}
                                    </span>
                                    <span class="font-mono text-foreground">{{ money(line.subtotal) }}</span>
                                </label>
                            </li>
                        </ul>

                        <Separator />

                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-foreground">Subtotal seleccionado</span>
                            <span class="font-mono text-lg font-bold text-foreground">{{ money(subtotalSeleccionado) }}</span>
                        </div>

                        <Button size="lg" :disabled="selectedItemIds.length === 0 || processing" @click="confirmPaymentByItems">
                            <Spinner v-if="processing" class="size-4" />
                            {{ processing ? 'Cobrando…' : `Registrar pago del grupo · ${money(subtotalSeleccionado)}` }}
                        </Button>
                    </template>
```

- [ ] **Step 5: Mostrar los ítems de un pago de grupo en el historial**

En el mismo archivo, dentro del `<li v-for="payment in pagosRegistrados" ...>` del historial de pagos, agregar una línea con los ítems del pago (si los tiene) entre el `<span>` del cobrador y el cierre del `<div class="flex flex-col">`:

```html
                                        <span v-if="payment.items?.length" class="text-xs text-muted-foreground">
                                            {{ payment.items.map((item) => item.menu_item?.name ?? `Platillo #${item.menu_item_id}`).join(', ') }}
                                        </span>
```

- [ ] **Step 6: Lint y types**

```bash
npm run lint:check
npm run types:check
```

Expected: ambos sin errores nuevos (si `lint:check` reporta auto-fixeable, correr `npm run lint` y revisar el diff antes de continuar).

- [ ] **Step 7: Build para verificación manual (Task 4)**

```bash
npm run build
```

Expected: build exitoso sin warnings de Vite sobre módulos faltantes.

- [ ] **Step 8: Commitear**

```bash
git add resources/js/types/models.ts resources/js/pages/mesas/Cobro.vue
git commit -m "feat(cobro): UI de split por ítems en Cobro.vue (REDEV-29)"
```

(Los archivos generados de `resources/js/routes`/`resources/js/actions` están en `.gitignore` — no se commitean.)

---

## Task 4: Verificación completa y cierre de documentación

**Files:**
- Modify: `_ai/specs/division-de-cuenta.spec.md` (marcar checkboxes de Test Cases y Definition of Done, flip de Status)
- Modify: `_ai/docs/decision-log.md` (marcar la brecha original 🟢 Resuelta + nueva entrada)
- Modify: `_ai/docs/spec-registry.md` (si aplica)
- Modify: `_ai/CONTEXT.md` (nota breve, mismo patrón que otras entradas de cierre)

- [ ] **Step 1: Suite completa**

```bash
php artisan test --compact
```

Expected: PASS — incluye los 184 tests preexistentes (180 passed / 4 skipped) más los 15 nuevos de esta feature (6 unit + 9 feature), sin regresiones. Si algo preexistente falla, detente y repórtalo — no continúes con la verificación manual hasta entender por qué.

- [ ] **Step 2: Levantar el servidor de dev**

```bash
lsof -i :8000
```

Si el puerto 8000 está libre, usar `composer run dev`. Si está ocupado (sesión concurrente, trampa ya documentada en `decision-log.md`), usar en su lugar:

```bash
php artisan serve --port=8001
```

(los assets ya están compilados por `npm run build` del Task 3, Step 7 — no hace falta `npm run dev` para esta verificación).

- [ ] **Step 3: Verificación visual en browser real**

Navegar a `http://demo.localhost:8000` (o `:8001` si se usó el puerto alterno), login con `admin.qa@demo.test` / `password` (resetear la password vía tinker si no funciona — ver memoria de verificación de este proyecto).

Crear una mesa y orden de prueba dedicada (no reutilizar mesas de otras sesiones en la DB compartida) con al menos 2 platillos distintos, llevarla a `por_cobrar`, y verificar en `/mesas/{id}/cobro`:
1. El toggle "Por monto"/"Por ítems" aparece y cambia el panel.
2. En "Por ítems": seleccionar un subconjunto de ítems, el subtotal se actualiza en vivo, confirmar un pago parcial → saldo pendiente se actualiza, esos ítems ya no aparecen en la lista seleccionable.
3. Un segundo pago de grupo con el resto de los ítems cierra la cuenta y libera la mesa (vuelve al mapa de mesas).
4. Repetir en una cuenta nueva mezclando un pago por ítems + un pago final "Por monto" que cierre — ambos modos conviven sin errores.
5. El modo "Por monto" solo (sin tocar "Por ítems") se ve y funciona igual que antes de este cambio.
6. Sin errores en consola del navegador. Repetir en dark mode (toggle del layout).
7. Borrar la mesa/orden de prueba al terminar (no ensuciar la DB compartida).

- [ ] **Step 4: Actualizar `_ai/specs/division-de-cuenta.spec.md`**

En la sección `## Ampliación (REDEV-29): Split por Ítems`:
- Cambiar `### Status` de `[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented` a `[x] Draft  [ ] Review  [ ] Approved  [x] Implemented`.
- Marcar como `[x]` todos los checkboxes de `#### Unit Tests`, `#### Integration Tests` y `#### E2E Tests` que se verificaron en los Steps 1 y 3.
- Marcar como `[x]` todos los checkboxes de `### Definition of Done (ampliación)`.

- [ ] **Step 5: Actualizar `_ai/docs/decision-log.md`**

Agregar una entrada nueva (después de la última entrada del archivo), siguiendo el formato de las entradas existentes: fecha de hoy, título `### 2026-08-12 — REDEV-29: Split por Ítems implementado`, con: qué se implementó (referencia a la sección del spec), qué se verificó (comando de tests + resultado real observado en el Step 1, y el detalle de la verificación visual del Step 3), y cualquier entorno/trampa de worktree encontrada en el Task 0 que no estuviera ya documentada.

Además, en la entrada existente `### 2026-08-12 — PASO 0 de División de Cuenta (US-3.2, #12): mecanismo de split`, agregar una línea al final indicando que la opción (b) diferida ahí quedó resuelta por REDEV-29, con referencia a la nueva entrada.

- [ ] **Step 6: Actualizar `_ai/docs/spec-registry.md` y `_ai/CONTEXT.md` si aplica**

En `spec-registry.md`, la fila `#12 División de Cuenta` ya está `✅ Implemented` — no cambia el estado macro (la ampliación es parte de la misma fila). Si se agrega una nota, que sea breve, apuntando al spec.

En `_ai/CONTEXT.md`, agregar una entrada breve al final del bloque de notas de sesión (mismo estilo que las entradas de "2026-08-12 — ..." ya existentes), resumiendo en 2-3 líneas: split por ítems implementado, ambos modos conviven, tests + verificación visual pasaron.

- [ ] **Step 7: Commit final**

```bash
vendor/bin/pint --dirty --format agent
git add _ai/specs/division-de-cuenta.spec.md _ai/docs/decision-log.md _ai/docs/spec-registry.md _ai/CONTEXT.md
git commit -m "docs: cierre de REDEV-29 — split por ítems (spec, decision-log, registry)"
```

No hacer merge a `main` — el ticket pide dejar la rama lista para revisión manual.

- [ ] **Step 8: Reportar estado final**

Resumir: comando de suite completa corrido y su resultado real (no de memoria), archivos tocados, y que la rama queda sin mergear a `main` a la espera de revisión.
