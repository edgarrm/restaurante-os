<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { verify } from '@/routes/pin';

// F-07 (_ai/docs/threat-model.md — ver _ai/specs/bloqueo-tablet-pin.spec.md):
// modal que el submit de cobro abre cuando el servidor rechaza el pago con
// el error `pin` (verificación ausente/expirada). `useForm` propio, no el
// `errorMessage`/`page.props.errors` de la pantalla que lo abre — así un
// PIN incorrecto se muestra dentro del modal sin interferir con el estado
// de la petición de pago original.
const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    verified: [];
}>();

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const form = useForm({ pin: '' });

// Cada vez que el gate vuelve a abrir el modal (ej. tras un intento
// fallido reciente o una nueva verificación expirada), arranca limpio.
watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

function submit() {
    form.post(verify.url(), {
        preserveScroll: true,
        onSuccess: () => {
            isOpen.value = false;
            form.reset();
            emit('verified');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Verifica tu PIN</DialogTitle>
                    <DialogDescription>
                        Por seguridad, ingresa tu PIN de cobro para continuar.
                        No has verificado tu PIN en los últimos 5 minutos en
                        este dispositivo.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="payment-pin">PIN</Label>
                    <Input
                        id="payment-pin"
                        v-model="form.pin"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="4"
                        autocomplete="off"
                        autofocus
                        class="font-mono tracking-[0.5em]"
                    />
                    <InputError :message="form.errors.pin" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancelar</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.pin.length !== 4"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ form.processing ? 'Verificando…' : 'Verificar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
