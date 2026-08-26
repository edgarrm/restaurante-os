<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { paymentMethodOptions } from '@/lib/paymentMethods';
import type { PaymentMethod } from '@/types';

defineProps<{
    modelValue: PaymentMethod;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: PaymentMethod];
}>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <Label>Método de pago</Label>
        <div class="grid grid-cols-3 gap-2">
            <Button
                v-for="option in paymentMethodOptions"
                :key="option.value"
                type="button"
                size="sm"
                :variant="modelValue === option.value ? 'default' : 'outline'"
                @click="emit('update:modelValue', option.value)"
            >
                {{ option.label }}
            </Button>
        </div>
    </div>
</template>
