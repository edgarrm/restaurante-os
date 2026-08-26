<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    deletePasskey,
    isPasskeySupported,
    PasskeyError,
    registerPasskey,
} from '@/lib/passkeys';
import { edit } from '@/routes/passkeys';

type PasskeyItem = {
    id: number;
    name: string;
    authenticator: string | null;
    lastUsedAt: string | null;
    createdAt: string | null;
};

defineProps<{
    passkeys: PasskeyItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Passkeys',
                href: edit(),
            },
        ],
    },
});

const supported = isPasskeySupported();
const newPasskeyName = ref('');
const registering = ref(false);
const deletingId = ref<number | null>(null);

function refresh(): void {
    router.reload({ only: ['passkeys'] });
}

async function onRegister(): Promise<void> {
    if (!newPasskeyName.value.trim()) {
        return;
    }

    registering.value = true;

    try {
        await registerPasskey(newPasskeyName.value.trim());
        newPasskeyName.value = '';
        toast.success('Passkey registrada.');
        refresh();
    } catch (error) {
        toast.error(error instanceof PasskeyError ? error.message : 'No fue posible registrar la passkey.');
    } finally {
        registering.value = false;
    }
}

async function onDelete(passkey: PasskeyItem): Promise<void> {
    deletingId.value = passkey.id;

    try {
        await deletePasskey(passkey.id);
        toast.success('Passkey revocada.');
        refresh();
    } catch (error) {
        toast.error(error instanceof PasskeyError ? error.message : 'No fue posible revocar la passkey.');
    } finally {
        deletingId.value = null;
    }
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Nunca';
    }

    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head title="Passkeys" />

    <h1 class="sr-only">Passkeys</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Passkeys"
            description="Ingresa sin contraseña usando Face ID, Touch ID o el PIN de tu dispositivo. Cada passkey queda ligada a este restaurante — no funciona en otro."
        />

        <div v-if="!supported" class="rounded-lg border p-4 text-sm text-muted-foreground">
            Este navegador o dispositivo no soporta passkeys. Puedes seguir
            iniciando sesión con tu contraseña normalmente.
        </div>

        <template v-else>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="grid flex-1 gap-2">
                    <Label for="passkey-name">Nombre de la passkey</Label>
                    <Input
                        id="passkey-name"
                        v-model="newPasskeyName"
                        placeholder='Ej. "iPad de la barra"'
                        :disabled="registering"
                        @keydown.enter.prevent="onRegister"
                    />
                </div>
                <Button
                    :disabled="registering || !newPasskeyName.trim()"
                    data-test="register-passkey-button"
                    @click="onRegister"
                >
                    <Spinner v-if="registering" />
                    Registrar passkey
                </Button>
            </div>

            <div v-if="passkeys.length === 0" class="text-sm text-muted-foreground">
                Todavía no tienes ninguna passkey registrada.
            </div>

            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="item in passkeys"
                    :key="item.id"
                    class="flex items-center justify-between gap-4 p-4"
                >
                    <div class="space-y-0.5">
                        <p class="font-medium">{{ item.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ item.authenticator ?? 'Autenticador' }} ·
                            Último uso: {{ formatDate(item.lastUsedAt) }}
                        </p>
                    </div>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button
                                variant="destructive"
                                size="sm"
                                :disabled="deletingId === item.id"
                                :data-test="`revoke-passkey-${item.id}`"
                            >
                                Revocar
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader class="space-y-3">
                                <DialogTitle>¿Revocar "{{ item.name }}"?</DialogTitle>
                                <DialogDescription>
                                    No podrás volver a usar esta passkey para
                                    iniciar sesión. Si perdiste el
                                    dispositivo, esta es la forma correcta de
                                    revocarla.
                                </DialogDescription>
                            </DialogHeader>

                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancelar</Button>
                                </DialogClose>

                                <DialogClose as-child>
                                    <Button
                                        variant="destructive"
                                        :disabled="deletingId === item.id"
                                        @click="onDelete(item)"
                                    >
                                        Revocar
                                    </Button>
                                </DialogClose>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </li>
            </ul>
        </template>
    </div>
</template>
