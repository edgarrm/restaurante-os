<?php

namespace App\Http\Controllers;

use App\Actions\MenuItems\CreateMenuItemAction;
use App\Actions\MenuItems\ToggleMenuItemAvailabilityAction;
use App\Actions\MenuItems\UpdateMenuItemAction;
use App\Models\MenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MenuItemController extends Controller
{
    /**
     * Lista los platillos del restaurante, ordenados por categoría y
     * nombre para que el frontend los agrupe (_ai/specs/gestion-menu.spec.md).
     */
    public function index(): Response
    {
        return Inertia::render('menu/Index', [
            'menuItems' => MenuItem::query()->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateMenuItemAction $action): RedirectResponse
    {
        Gate::authorize('create', MenuItem::class);

        $action->handle([
            'name' => $request->string('name')->toString(),
            'category' => $request->string('category')->toString(),
            'price' => $request->input('price'),
        ]);

        return to_route('menu.index');
    }

    public function update(Request $request, MenuItem $menuItem, UpdateMenuItemAction $action): RedirectResponse
    {
        Gate::authorize('update', $menuItem);

        $action->handle($menuItem, [
            'name' => $request->string('name')->toString(),
            'category' => $request->string('category')->toString(),
            'price' => $request->input('price'),
        ]);

        return to_route('menu.index');
    }

    public function toggleAvailability(MenuItem $menuItem, ToggleMenuItemAvailabilityAction $action): RedirectResponse
    {
        Gate::authorize('update', $menuItem);

        $action->handle($menuItem);

        return to_route('menu.index');
    }
}
