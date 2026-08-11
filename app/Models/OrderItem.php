<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelo mínimo — ver la nota en la migración `create_order_items_table`.
 * Solo existe hoy para que UpdateMenuItemAction pueda probar que
 * `unit_price` es un snapshot; _ai/specs/toma-de-pedido.spec.md (#5)
 * completa el resto del dominio.
 *
 * Sin `BelongsToTenant`: OrderItem no lleva `tenant_id` propio, hereda el
 * aislamiento vía `Order` (ver _ai/docs/data-model.md).
 *
 * @property int $id
 * @property int $order_id
 * @property int $menu_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property OrderItemStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['order_id', 'menu_item_id', 'quantity', 'unit_price', 'status'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'status' => OrderItemStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
