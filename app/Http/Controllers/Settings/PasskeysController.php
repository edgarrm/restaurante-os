<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Solo lista las passkeys del usuario autenticado — el registro/borrado
 * real pega directo a los endpoints de `laravel/passkeys`
 * (`/user/passkeys/options`, `/user/passkeys`, `/user/passkeys/{passkey}`)
 * desde `resources/js/lib/passkeys.ts`, ver _ai/specs/passkeys.spec.md.
 */
class PasskeysController extends Controller
{
    /**
     * Show the user's passkeys settings page.
     */
    public function edit(Request $request): Response
    {
        $passkeys = $request->user()
            ->passkeys()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
                'createdAt' => $passkey->created_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/Passkeys', [
            'passkeys' => $passkeys,
        ]);
    }
}
