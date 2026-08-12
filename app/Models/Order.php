<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Ver _ai/specs/toma-de-pedido.spec.md (#5) para el dominio completo de
 * Order (antes modelo mínimo, solo para `Table::orders()`/`DeleteTableAction`
 * — ver _ai/specs/gestion-mesas.spec.md).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $table_id
 * @property int $opened_by
 * @property OrderStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['table_id', 'opened_by', 'status', 'opened_at', 'closed_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Total de la cuenta: suma de `quantity * unit_price` de sus `items`.
     * Única fuente de verdad, usada por `CloseOrderAction` y
     * `AddPaymentToOrderAction` (_ai/specs/division-de-cuenta.spec.md) para
     * no duplicar el cálculo.
     */
    public function total(): float
    {
        return (float) $this->items->sum(
            fn (OrderItem $item): float => $item->quantity * (float) $item->unit_price
        );
    }
}
