<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Table;
use Illuminate\Support\Facades\Validator;

class UpdateTableAction
{
    /**
     * Actualiza nombre y capacidad de una mesa existente sin tocar `status`
     * (ver _ai/specs/gestion-mesas.spec.md).
     *
     * @param  array{name: string, capacity: int|string}  $data
     */
    public function handle(Table $table, array $data): Table
    {
        Validator::make($data, [
            'name' => ['required', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
        ], [
            'capacity.min' => 'La capacidad debe ser al menos 1.',
        ])->validate();

        $table->update([
            'name' => $data['name'],
            'capacity' => $data['capacity'],
        ]);

        return $table;
    }
}
