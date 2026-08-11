<?php

namespace App\Models;

use App\Enums\TableStatus;
use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property int $capacity
 * @property TableStatus $status
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'capacity'])]
class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * Mirror del default de la columna `status` (ver migración) para que una
     * mesa nueva sin guardar ya lea `libre` antes del primer `save()`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TableStatus::Libre->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'status' => TableStatus::class,
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
