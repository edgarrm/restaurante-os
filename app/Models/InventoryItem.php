<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $unit
 * @property string $quantity_on_hand
 * @property string $low_stock_threshold
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'unit', 'low_stock_threshold'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Mirror del default de la columna `quantity_on_hand` (ver migración)
     * para que un insumo nuevo sin guardar ya lea 0 antes del primer
     * `save()`, igual que `Table::$attributes` para `status`.
     * `quantity_on_hand` se excluye deliberadamente de `$fillable`: solo se
     * muta vía `forceFill()` desde las Actions de Inventory (ver
     * _ai/specs/inventario.spec.md, Security Considerations, y
     * .ai/rules/actions.md).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_on_hand' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
        ];
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
