<?php

namespace App\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $category
 * @property string $price
 * @property bool $available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'category', 'price'])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Mirror del default de la columna `available` (ver migración) para que
     * un platillo nuevo sin guardar ya lea `true` antes del primer
     * `save()`, igual que `Table::$attributes` para `status`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'available' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'available' => 'boolean',
        ];
    }
}
