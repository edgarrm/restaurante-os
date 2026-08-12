<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { close as closeRoute, show as cobroShow } from '@/routes/cobro';
import { index as mesasIndex } from '@/routes/mesas';
import type { Order, OrderStatus, PaymentMethod, Table } from '@/types';

const { table, order } = defineProps<{
    table: Table;
    order: Order;
}>();

// setLayoutProps (Inertia v3), no defineOptions({ layout: {...} }): ese
// objeto se evalúa una sola vez a nivel de módulo, antes de que existan
// props de instancia — referenciar `table` ahí lanza un ReferenceError en
// runtime (ver Pedido.vue).
setLayoutProps({
    breadcrumbs: [
        { title: 'Mesas', href: mesasIndex() },
        { title: table.name, href: cobroShow(table.id) },
    ],
});

const orderStatusLabel: Record<OrderStatus, string> = {
    abierta: 'Abierta',
    enviada_cocina: 'Enviada a cocina',
    lista: 'Lista',
    por_cobrar: 'Por cobrar',
    pagada: 'Pagada',
    cancelada: 'Cancelada',
};

const cuentaLines = computed(() =>
    (order.items ?? []).map((orderItem) => ({
        orderItem,
        subtotal: Number(orderItem.unit_price) * orderItem.quantity,
    })),
);

const total = computed(() => cuentaLines.value.reduce((sum, line) => sum + line.subtotal, 0));

function money(value: number): string {
    return `$${value.toFixed(2)}`;
}

// Todos los 422 de dominio de este flujo viajan como ValidationException,
// así que llegan aquí como cualquier error de formulario normal de Inertia
// (ver PaymentController::close y decision-log.md, 2026-08-12).
const page = usePage();
const errorMessage = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;
    const values = Object.values(errors ?? {});

    return values.length > 0 ? values[0] : null;
});

const methodOptions: { value: PaymentMethod; label: string }[] = [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'transferencia', label: 'Transferencia' },
];

const method = ref<PaymentMethod>('efectivo');

// Por defecto, el total exacto de la orden (Happy Path #3) — el mesero
// puede ajustarlo hacia arriba (billete grande, ver Edge Cases) pero no
// hacia un monto insuficiente, ese caso lo rechaza el servidor.
const amount = ref(total.value.toFixed(2));

const change = computed(() => {
    const value = Number(amount.value);

    return Number.isFinite(value) && value > total.value ? value - total.value : 0;
});

const canConfirm = computed(() => cuentaLines.value.length > 0 && Number(amount.value) > 0);

const processing = ref(false);

function confirmPayment() {
    if (!canConfirm.value || processing.value) {
        return;
    }

    processing.value = true;
    router.post(
        closeRoute.url(table.id),
        { amount: amount.value, method: method.value },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${table.name} · Cobro`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="font-mono text-2xl font-bold tracking-tight text-foreground">
                    {{ table.name }}
                </h1>
                <Badge variant="secondary">
                    {{ orderStatusLabel[order.status] }}
                </Badge>
            </div>
            <Button as-child variant="outline">
                <Link :href="mesasIndex()">Volver a Mesas</Link>
            </Button>
        </div>

        <Alert v-if="errorMessage" variant="destructive">
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>

        <div class="grid flex-1 grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
            <!-- La Cuenta -->
            <Card class="h-fit">
                <CardHeader>
                    <CardTitle class="font-mono">La Cuenta</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div v-if="cuentaLines.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        Esta mesa no tiene platillos en la cuenta.
                    </div>

                    <ul v-else class="flex flex-col gap-3">
                        <li v-for="line in cuentaLines" :key="line.orderItem.id" class="flex items-start justify-between gap-2">
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-medium text-foreground">
                                    {{ line.orderItem.menu_item?.name ?? `Platillo #${line.orderItem.menu_item_id}` }}
                                </span>
                                <span class="font-mono text-xs text-muted-foreground">
                                    {{ money(Number(line.orderItem.unit_price)) }} c/u × {{ line.orderItem.quantity }}
                                </span>
                            </div>
                            <span class="font-mono text-sm font-medium text-foreground">
                                {{ money(line.subtotal) }}
                            </span>
                        </li>
                    </ul>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-foreground">Total</span>
                        <span class="font-mono text-lg font-bold text-foreground">{{ money(total) }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Cobrar -->
            <Card class="h-fit lg:sticky lg:top-6">
                <CardHeader>
                    <CardTitle class="font-mono">Cobrar</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <Label>Método de pago</Label>
                        <div class="grid grid-cols-3 gap-2">
                            <Button
                                v-for="option in methodOptions"
                                :key="option.value"
                                type="button"
                                size="sm"
                                :variant="method === option.value ? 'default' : 'outline'"
                                @click="method = option.value"
                            >
                                {{ option.label }}
                            </Button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="amount">Monto recibido</Label>
                        <Input id="amount" v-model="amount" type="number" min="0" step="0.01" class="font-mono" />
                    </div>

                    <div v-if="change > 0" class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Cambio a dar</span>
                        <span class="font-mono font-medium text-foreground">{{ money(change) }}</span>
                    </div>

                    <Button size="lg" :disabled="!canConfirm || processing" @click="confirmPayment">
                        <Spinner v-if="processing" class="size-4" />
                        {{ processing ? 'Cobrando…' : `Confirmar pago · ${money(total)}` }}
                    </Button>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
