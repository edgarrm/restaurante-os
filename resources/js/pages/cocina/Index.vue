<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index as cocinaIndex } from '@/routes/cocina';
import { markReady as markReadyRoute } from '@/routes/cocina/items';
import type { Order, OrderItem, OrderItemStatus } from '@/types';

const { orders, completedOrders } = defineProps<{
    orders: Order[];
    completedOrders: Order[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cocina', href: cocinaIndex() }],
    },
});

// Sondeo cada 4s — mismo intervalo ya validado en Mapa de Mesas (ADR-005);
// el spec de esta pantalla pide 3-5s (Happy Path #3).
usePoll(4000);

const ACTIONABLE_STATUSES: OrderItemStatus[] = ['pendiente', 'preparando'];
const URGENT_AFTER_MINUTES = 15;

function isActionable(item: OrderItem): boolean {
    return ACTIONABLE_STATUSES.includes(item.status);
}

// Sin timer propio: cada poll reemplaza `orders`, lo que ya fuerza un
// re-render y recalcula el tiempo transcurrido con la misma frescura del
// spec (3-5s) — no hace falta un `setInterval` adicional.
function elapsedMinutes(dateString: string): number {
    return Math.max(0, Math.floor((Date.now() - new Date(dateString).getTime()) / 60000));
}

function isUrgent(order: Order): boolean {
    return elapsedMinutes(order.opened_at) >= URGENT_AFTER_MINUTES;
}

const itemStatusLabel: Record<OrderItemStatus, string> = {
    pendiente: 'Pendiente',
    preparando: 'Preparando',
    listo: 'Listo',
    servido: 'Servido',
};

// Marcar un ítem (o una orden completa) es un botón directo, sin diálogo
// de confirmación — el spec prioriza velocidad sobre prevención de error
// en este flujo, y el endpoint es idempotente ante doble tap.
const markingItemId = ref<number | null>(null);
const markingOrderId = ref<number | null>(null);
const isBusy = computed(() => markingItemId.value !== null || markingOrderId.value !== null);

function markItem(item: OrderItem) {
    if (isBusy.value) {
        return;
    }

    markingItemId.value = item.id;
    router.patch(markReadyRoute.url(item.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            markingItemId.value = null;
        },
    });
}

// No existe un endpoint de "marcar toda la orden" en el servidor (PASO 0,
// ver decision-log.md) — se encadenan PATCHs por cada ítem pendiente, uno
// a la vez, para no disparar varias visitas Inertia concurrentes sobre la
// misma Order.
function markOrder(order: Order) {
    if (isBusy.value) {
        return;
    }

    const pendingIds = (order.items ?? []).filter(isActionable).map((item) => item.id);

    if (pendingIds.length === 0) {
        return;
    }

    markingOrderId.value = order.id;

    const markNext = (ids: number[]) => {
        const [id, ...rest] = ids;

        if (id === undefined) {
            markingOrderId.value = null;

            return;
        }

        router.patch(markReadyRoute.url(id), {}, {
            preserveScroll: true,
            onFinish: () => markNext(rest),
        });
    };

    markNext(pendingIds);
}
</script>

<template>
    <Head title="Cocina" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Cocina
            </h1>
        </div>

        <div
            v-if="orders.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay pedidos pendientes.
            </p>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="order in orders"
                :key="order.id"
                class="border-2"
                :class="isUrgent(order) ? 'border-destructive/50 bg-destructive/5' : 'border-border'"
            >
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 font-mono text-lg">
                        {{ order.table?.name ?? `Mesa #${order.table_id}` }}
                    </CardTitle>
                    <Badge :variant="isUrgent(order) ? 'destructive' : 'outline'" class="w-fit">
                        {{ elapsedMinutes(order.opened_at) }} min
                    </Badge>
                    <CardAction>
                        <Button
                            size="sm"
                            :disabled="isBusy || !(order.items ?? []).some(isActionable)"
                            @click="markOrder(order)"
                        >
                            <Spinner v-if="markingOrderId === order.id" class="size-3.5" />
                            Listo (orden)
                        </Button>
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="item in order.items ?? []"
                            :key="item.id"
                            class="flex items-center justify-between gap-3"
                        >
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-medium text-foreground">
                                    {{ item.menu_item?.name ?? `Platillo #${item.menu_item_id}` }}
                                </span>
                                <span class="font-mono text-xs text-muted-foreground">×{{ item.quantity }}</span>
                            </div>

                            <Button
                                v-if="isActionable(item)"
                                size="sm"
                                variant="outline"
                                :disabled="isBusy"
                                @click="markItem(item)"
                            >
                                <Spinner v-if="markingItemId === item.id" class="size-3.5" />
                                Listo
                            </Button>
                            <Badge v-else variant="secondary">{{ itemStatusLabel[item.status] }}</Badge>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <div v-if="completedOrders.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                Completadas
            </h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="order in completedOrders" :key="order.id" class="opacity-80">
                    <CardHeader>
                        <CardTitle class="font-mono text-lg">
                            {{ order.table?.name ?? `Mesa #${order.table_id}` }}
                        </CardTitle>
                        <Badge class="w-fit border-status-libre/40 bg-status-libre/10 text-status-libre" variant="outline">
                            Lista
                        </Badge>
                    </CardHeader>
                    <CardContent>
                        <ul class="flex flex-col gap-1">
                            <li
                                v-for="item in order.items ?? []"
                                :key="item.id"
                                class="flex items-center justify-between text-sm text-muted-foreground"
                            >
                                <span>{{ item.menu_item?.name ?? `Platillo #${item.menu_item_id}` }}</span>
                                <span class="font-mono">×{{ item.quantity }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
