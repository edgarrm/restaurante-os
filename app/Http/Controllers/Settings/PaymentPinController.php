<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Staff\SetPaymentPinAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentPinController extends Controller
{
    /**
     * Pantalla de autoservicio del PIN de cobro (F-07,
     * _ai/docs/threat-model.md — ver _ai/specs/bloqueo-tablet-pin.spec.md).
     * Igual que Profile/Security/Appearance, sin restricción de rol: un
     * PIN configurado por `cocina` simplemente nunca se usa (el gate real
     * solo se ejecuta en las rutas de cobro, ya restringidas a
     * `role:admin,mesero`).
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/PaymentPin', [
            'hasPin' => $request->user()->pin_hash !== null,
        ]);
    }

    /**
     * Fija o cambia el PIN del usuario autenticado. Nunca recibe un
     * `user_id` — siempre opera sobre `$request->user()` (F-04, mismo
     * criterio que el resto de Settings).
     */
    public function update(Request $request, SetPaymentPinAction $action): RedirectResponse
    {
        $action->handle($request->user(), [
            'pin' => $request->string('pin')->toString(),
            'pin_confirmation' => $request->string('pin_confirmation')->toString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('PIN actualizado.')]);

        return back();
    }
}
