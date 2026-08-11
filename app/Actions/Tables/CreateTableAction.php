<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Table;
use Illuminate\Support\Facades\Validator;

class CreateTableAction
{
    /**
     * Crea una mesa nueva con `status=libre` por defecto (ver
     * _ai/specs/gestion-mesas.spec.md). `tenant_id` lo rellena
     * `BelongsToTenant` automáticamente.
     *
     * @param  array{name: string, capacity: int|string}  $data
     */
    public function handle(array $data): Table
    {
        Validator::make($data, [
            'name' => ['required', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
        ], [
            'capacity.min' => 'La capacidad debe ser al menos 1.',
        ])->validate();

        return Table::create([
            'name' => $data['name'],
            'capacity' => $data['capacity'],
        ]);
    }
}
