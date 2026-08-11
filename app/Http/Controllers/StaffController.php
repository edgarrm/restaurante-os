<?php

namespace App\Http\Controllers;

use App\Actions\Staff\CreateStaffAccountAction;
use App\Actions\Staff\DeactivateStaffAccountAction;
use App\Actions\Staff\UpdateStaffRoleAction;
use App\Enums\Role;
use App\Exceptions\Staff\InvalidStaffRoleException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    /**
     * Lista las cuentas de staff del restaurante, sin incluir cuentas admin
     * (_ai/specs/gestion-staff.spec.md, Happy Path #2).
     */
    public function index(): Response
    {
        return Inertia::render('staff/Index', [
            'staff' => User::query()->where('role', '!=', Role::Admin)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateStaffAccountAction $action): RedirectResponse
    {
        Gate::authorize('create', User::class);

        try {
            $action->handle([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'role' => $request->string('role')->toString(),
            ]);
        } catch (InvalidStaffRoleException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('staff.index');
    }

    public function update(Request $request, User $user, UpdateStaffRoleAction $action): RedirectResponse
    {
        Gate::authorize('update', $user);

        try {
            $action->handle($user, $request->string('role')->toString());
        } catch (InvalidStaffRoleException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('staff.index');
    }

    public function deactivate(User $user, DeactivateStaffAccountAction $action): RedirectResponse
    {
        Gate::authorize('update', $user);

        $action->handle($user);

        return to_route('staff.index');
    }
}
