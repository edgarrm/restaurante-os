/**
 * Wrapper delgado sobre `navigator.credentials.create()/get()` + el formato
 * JSON nativo de WebAuthn Level 3 (`PublicKeyCredential.parse*FromJSON`,
 * `credential.toJSON()`), soportado por los navegadores evergreen. Sin
 * dependencia npm nueva — ver _ai/specs/passkeys.spec.md, PASO 0.
 *
 * No usa el cliente de Inertia (estos endpoints son JSON planos de
 * `laravel/passkeys`, no páginas Inertia) — `fetch()` + el cookie
 * `XSRF-TOKEN` que Laravel ya deja en cada respuesta (mismo mecanismo que
 * usa Axios/Inertia internamente para peticiones same-origin).
 */
import passkey from '@/routes/passkey';

export class PasskeyError extends Error {}

/**
 * Detección de soporte: si falta, tanto el botón de login como la sección
 * de registro en Settings se ocultan (ver _ai/specs/passkeys.spec.md, Edge
 * Cases).
 */
export function isPasskeySupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.PublicKeyCredential !== 'undefined' &&
        typeof PublicKeyCredential.parseCreationOptionsFromJSON ===
            'function' &&
        typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function'
    );
}

function xsrfTokenFromCookie(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function postJson(url: string, body: unknown): Promise<Response> {
    const token = xsrfTokenFromCookie();

    // Sin forzar `Accept: application/json` a propósito: mantiene
    // `$request->wantsJson()` en `false` en el servidor, para que
    // `/passkeys/login` siga la misma rama de redirect por rol que el login
    // por contraseña — ver _ai/specs/passkeys.spec.md, "Redirect post-login
    // por rol".
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new PasskeyError(await errorMessage(response));
    }

    return response;
}

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
        throw new PasskeyError(await errorMessage(response));
    }

    return response.json();
}

async function errorMessage(response: Response): Promise<string> {
    const body = await response.json().catch(() => null);
    const first = body?.errors?.credential?.[0] ?? body?.message;

    return first ?? 'No fue posible completar la operación.';
}

function isCancellation(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'NotAllowedError';
}

type PasskeyOptionsResponse<T> = { options: T };

/**
 * Registra una passkey nueva para el usuario autenticado.
 */
export async function registerPasskey(
    name: string,
): Promise<{ id: string; name: string }> {
    const { options } = await getJson<
        PasskeyOptionsResponse<PublicKeyCredentialCreationOptionsJSON>
    >(passkey.registrationOptions.url());

    let credential: Credential | null;

    try {
        credential = await navigator.credentials.create({
            publicKey:
                PublicKeyCredential.parseCreationOptionsFromJSON(options),
        });
    } catch (error) {
        throw new PasskeyError(
            isCancellation(error)
                ? 'Operación cancelada.'
                : 'No fue posible crear la passkey.',
        );
    }

    if (!(credential instanceof PublicKeyCredential)) {
        throw new PasskeyError('No fue posible crear la passkey.');
    }

    const response = await postJson(passkey.store.url(), {
        name,
        credential: credential.toJSON(),
    });

    return response.json();
}

/**
 * Verifica una passkey (sin username, discoverable credentials) e inicia
 * sesión. Al terminar, navega de verdad a la URL final (el redirect por rol
 * calculado por el servidor) en vez de dejar la SPA en el estado del login.
 */
export async function loginWithPasskey(remember = false): Promise<void> {
    const { options } = await getJson<
        PasskeyOptionsResponse<PublicKeyCredentialRequestOptionsJSON>
    >(passkey.loginOptions.url());

    let credential: Credential | null;

    try {
        credential = await navigator.credentials.get({
            publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options),
        });
    } catch (error) {
        throw new PasskeyError(
            isCancellation(error)
                ? 'Operación cancelada.'
                : 'No se encontró ninguna passkey.',
        );
    }

    if (!(credential instanceof PublicKeyCredential)) {
        throw new PasskeyError('No se encontró ninguna passkey.');
    }

    const response = await postJson(passkey.login.url(), {
        credential: credential.toJSON(),
        remember,
    });

    window.location.assign(response.url);
}

/**
 * Revoca una passkey del usuario autenticado. La ruta ya exige
 * `password.confirm` (ver `/settings/passkeys`, gateada con
 * `RequirePassword` a nivel de página).
 */
export async function deletePasskey(id: number): Promise<void> {
    const token = xsrfTokenFromCookie();

    const response = await fetch(passkey.destroy.url({ passkey: id }), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
    });

    if (!response.ok) {
        throw new PasskeyError(await errorMessage(response));
    }
}
