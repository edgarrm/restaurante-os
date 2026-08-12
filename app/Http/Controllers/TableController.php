<?php

namespace App\Http\Controllers;

use App\Actions\Tables\CreateTableAction;
use App\Actions\Tables\DeleteTableAction;
use App\Actions\Tables\UpdateTableAction;
use App\Exceptions\Tables\TableHasActiveOrderException;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    /**
     * Lista las mesas del restaurante (_ai/specs/gestion-mesas.spec.md).
     */
    public function index(): Response
    {
        return Inertia::render('tables/Index', [
            'tables' => Table::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateTableAction $action): RedirectResponse
    {
        Gate::authorize('create', Table::class);

        $action->handle([
            'name' => $request->string('name')->toString(),
            'capacity' => $request->string('capacity')->toString(),
        ]);

        return to_route('tables.index');
    }

    public function update(Request $request, Table $table, UpdateTableAction $action): RedirectResponse
    {
        Gate::authorize('update', $table);

        $action->handle($table, [
            'name' => $request->string('name')->toString(),
            'capacity' => $request->string('capacity')->toString(),
        ]);

        return to_route('tables.index');
    }

    public function destroy(Table $table, DeleteTableAction $action): RedirectResponse
    {
        Gate::authorize('delete', $table);

        try {
            $action->handle($table);
        } catch (TableHasActiveOrderException $exception) {
            // ValidationException, no abort(422, ...): un abort() plano no
            // trae el header X-Inertia, así que el cliente Inertia real lo
            // trata como respuesta "no-Inertia" y muestra un modal con el
            // HTML crudo del error en vez del mensaje del spec — mismo bug
            // ya documentado en OrderController/PaymentController/
            // ReservationController/InventarioController (ver
            // decision-log.md).
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return to_route('tables.index');
    }
}
