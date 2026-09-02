<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PaymentPinController from '@/actions/App/Http/Controllers/Settings/PaymentPinController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/pin';

type Props = {
    hasPin: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'PIN de cobro',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="PIN de cobro" />

    <h1 class="sr-only">PIN de cobro</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="PIN de cobro"
            description="Un PIN de 4 dígitos que se te pide antes de cobrar una cuenta si pasaron más de 5 minutos desde tu última verificación en este dispositivo."
        />

        <Form
            v-bind="PaymentPinController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            reset-on-error
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="pin">{{
                    props.hasPin ? 'Nuevo PIN' : 'PIN'
                }}</Label>
                <Input
                    id="pin"
                    name="pin"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="4"
                    autocomplete="off"
                    placeholder="••••"
                    class="mt-1 block w-full max-w-40 font-mono tracking-[0.5em]"
                />
                <InputError :message="errors.pin" />
            </div>

            <div class="grid gap-2">
                <Label for="pin_confirmation">Confirmar PIN</Label>
                <Input
                    id="pin_confirmation"
                    name="pin_confirmation"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="4"
                    autocomplete="off"
                    placeholder="••••"
                    class="mt-1 block w-full max-w-40 font-mono tracking-[0.5em]"
                />
                <InputError :message="errors.pin_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-pin-button">
                    Guardar
                </Button>
            </div>
        </Form>
    </div>
</template>
