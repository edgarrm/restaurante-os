<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    isPasskeySupported,
    loginWithPasskey,
    PasskeyError,
} from '@/lib/passkeys';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Iniciar sesión',
        description: 'Ingresa tu correo y contraseña para continuar',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const supportsPasskeys = isPasskeySupported();
const usingPasskey = ref(false);
const passkeyError = ref<string | null>(null);

async function onPasskeyLogin(): Promise<void> {
    passkeyError.value = null;
    usingPasskey.value = true;

    try {
        await loginWithPasskey();
    } catch (error) {
        passkeyError.value =
            error instanceof PasskeyError
                ? error.message
                : 'No fue posible iniciar sesión con passkey.';
        usingPasskey.value = false;
    }
}
</script>

<template>
    <Head title="Iniciar sesión" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div
        v-if="passkeyError"
        class="mb-4 text-center text-sm font-medium text-destructive"
    >
        {{ passkeyError }}
    </div>

    <div v-if="supportsPasskeys" class="mb-6">
        <Button
            type="button"
            variant="outline"
            class="w-full"
            :disabled="usingPasskey"
            data-test="login-with-passkey-button"
            @click="onPasskeyLogin"
        >
            <Spinner v-if="usingPasskey" />
            Ingresar con passkey
        </Button>

        <div class="mt-6 flex items-center gap-3 text-xs text-muted-foreground">
            <span class="h-px flex-1 bg-border" />
            o con tu contraseña
            <span class="h-px flex-1 bg-border" />
        </div>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="correo@ejemplo.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Contraseña</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        ¿Olvidaste tu contraseña?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Contraseña"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Recordarme</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Iniciar sesión
            </Button>
        </div>
    </Form>
</template>
