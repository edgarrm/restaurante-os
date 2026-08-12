<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Ver _ai/specs/reservas.spec.md (#8) para el dominio completo.
 *
 * A diferencia de `OrderItem`/`Payment` (#5/#7, sin `BelongsToTenant`
 * propio, heredan el aislamiento vía `Order`), `Reservation` sí lleva su
 * propio `tenant_id` — `table_id` es nullable, así que no hay una relación
 * padre confiable de la que heredarlo (ver _ai/docs/data-model.md).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $table_id
 * @property string $customer_name
 * @property string $customer_phone
 * @property int $party_size
 * @property Carbon $reserved_at
 * @property ReservationStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['table_id', 'customer_name', 'customer_phone', 'party_size', 'reserved_at', 'status'])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'reserved_at' => 'datetime',
            'status' => ReservationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
