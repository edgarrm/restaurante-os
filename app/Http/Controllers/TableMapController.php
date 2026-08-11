<?php

namespace App\Http\Controllers;

use App\Models\Table;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mapa de mesas (_ai/specs/mapa-de-mesas.spec.md, #4). Solo lectura para
 * mesero+admin — distinto de TableController (CRUD exclusivo de admin en
 * /mesas/gestion). Sin Policy: el spec dice explícitamente que no hay
 * reglas de autorización propias ("cualquier mesero ve todas las mesas"),
 * el middleware `role:admin,mesero` ya resuelve el acceso a esta pantalla
 * completa.
 */
class TableMapController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('mesas/Index', [
            'tables' => Table::query()->orderBy('name')->get(),
        ]);
    }
}
