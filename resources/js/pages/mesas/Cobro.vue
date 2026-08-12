<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { close as closeRoute, show as cobroShow } from '@/routes/cobro';
import { store as addPaymentRoute } from '@/routes/cobro/pagos';
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

// División de Cuenta (_ai/specs/division-de-cuenta.spec.md, US-3.2): la
// orden puede tener varios `payments` ya registrados (pagos parciales), no
// solo uno. El saldo pendiente reemplaza al total fijo como lo que falta
// por cobrar.
const pagosRegistrados = computed(() => order.payments ?? []);
const totalPagado = computed(() => pagosRegistrados.value.reduce((sum, payment) => sum + Number(payment.amount), 0));
const saldoPendiente = computed(() => Math.max(0, total.value - totalPagado.value));

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

const methodLabel = Object.fromEntries(methodOptions.map((option) => [option.value, option.label])) as Record<PaymentMethod, string>;

const method = ref<PaymentMethod>('efectivo');

// Por defecto, el saldo pendiente (no el total fijo) — Happy Path #3 de
// #7. En una cuenta sin pagos previos, saldo pendiente === total, así que
// el mesero que paga todo de una vez ve exactamente lo mismo que antes. El
// mesero puede ajustar el monto hacia arriba (billete grande) o hacia
// abajo (pago parcial, ver división-de-cuenta.spec.md); solo un monto ≤ 0
// lo rechaza el servidor.
const amount = ref(saldoPendiente.value.toFixed(2));

// Tras un pago parcial, Inertia recarga las props de la misma instancia del
// componente (no remonta) — sin este watch, el campo se quedaba con el
// monto del pago recién registrado en vez de reflejar el nuevo saldo
// pendiente (bug encontrado en verificación visual con browser real).
watch(saldoPendiente, (value) => {
    amount.value = value.toFixed(2);
});

// Un monto que cubre el saldo pendiente cierra la cuenta (mismo endpoint
// `close` de #7); uno menor es un pago parcial (endpoint nuevo, no cierra).
const isPagoFinal = computed(() => Number(amount.value) >= saldoPendiente.value);

const change = computed(() => {
    const value = Number(amount.value);

    return Number.isFinite(value) && isPagoFinal.value && value > saldoPendiente.value ? value - saldoPendiente.value : 0;
});

const canConfirm = computed(() => cuentaLines.value.length > 0 && saldoPendiente.value > 0 && Number(amount.value) > 0);

const processing = ref(false);

function confirmPayment() {
    if (!canConfirm.value || processing.value) {
        return;
    }

    processing.value = true;
    // Pago que cubre el saldo → mismo endpoint `close` de #7, sin cambios
    // (así el flujo de un solo pago no nota ninguna diferencia). Pago
    // parcial → endpoint nuevo, que no exige cubrir el total.
    const url = isPagoFinal.value ? closeRoute.url(table.id) : addPaymentRoute.url(table.id);

    router.post(
        url,
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

                    <!-- División de Cuenta (_ai/specs/division-de-cuenta.spec.md):
                         solo aparece si ya hay pagos parciales registrados,
                         para no cambiar nada visualmente en el flujo de un
                         solo pago. -->
                    <template v-if="pagosRegistrados.length > 0">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted-foreground">Pagado</span>
                            <span class="font-mono text-foreground">{{ money(totalPagado) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-foreground">Saldo pendiente</span>
                            <span class="font-mono text-lg font-bold text-foreground">{{ money(saldoPendiente) }}</span>
                        </div>

                        <Separator />

                        <div class="flex flex-col gap-2">
                            <span class="text-sm font-semibold text-foreground">Pagos registrados</span>
                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="payment in pagosRegistrados"
                                    :key="payment.id"
                                    class="flex items-center justify-between gap-2 text-sm"
                                >
                                    <div class="flex flex-col">
                                        <span class="text-foreground">{{ methodLabel[payment.method] }}</span>
                                        <span class="text-xs text-muted-foreground">{{ payment.collector?.name ?? 'Mesero' }}</span>
                                    </div>
                                    <span class="font-mono font-medium text-foreground">{{ money(Number(payment.amount)) }}</span>
                                </li>
                            </ul>
                        </div>
                    </template>
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
                        <div class="flex items-center justify-between">
                            <Label for="amount">Monto recibido</Label>
                            <!-- Solo cuando difiere del total (ya hay pagos
                                 previos) — en el flujo de un solo pago,
                                 saldo pendiente === total, no se duplica. -->
                            <span v-if="pagosRegistrados.length > 0" class="font-mono text-xs text-muted-foreground">
                                Saldo pendiente: {{ money(saldoPendiente) }}
                            </span>
                        </div>
                        <Input id="amount" v-model="amount" type="number" min="0" step="0.01" class="font-mono" />
                    </div>

                    <div v-if="change > 0" class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Cambio a dar</span>
                        <span class="font-mono font-medium text-foreground">{{ money(change) }}</span>
                    </div>

                    <Button size="lg" :disabled="!canConfirm || processing" @click="confirmPayment">
                        <Spinner v-if="processing" class="size-4" />
                        {{
                            processing
                                ? 'Cobrando…'
                                : `${isPagoFinal ? 'Confirmar pago' : 'Registrar pago parcial'} · ${money(Number(amount) || 0)}`
                        }}
                    </Button>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
