<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Minus, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { index as mesasIndex } from '@/routes/mesas';
import { addItem as addItemRoute, send as sendRoute, show as pedidoShow, updateItem as updateItemRoute } from '@/routes/pedido';
import type { MenuItem, Order, OrderItem, OrderStatus, Table } from '@/types';

const { table, menuItems, order } = defineProps<{
    table: Table;
    menuItems: MenuItem[];
    order: Order;
}>();

// defineOptions({ layout: {...} }) no sirve aquí: ese objeto se evalúa una
// sola vez, a nivel de módulo, antes de que existan props de instancia —
// referenciar `table` ahí lanza un ReferenceError en runtime. setLayoutProps
// (Inertia v3) sí corre dentro de setup(), con acceso a `table`.
setLayoutProps({
    breadcrumbs: [
        { title: 'Mesas', href: mesasIndex() },
        { title: table.name, href: pedidoShow(table.id) },
    ],
});

// Solo existe endpoint para agregar/incrementar y (desde esta sesión) para
// ajustar/quitar un OrderItem — ver UpdateOrderItemQuantityAction. Solo
// editable mientras la orden sigue `abierta`; una vez enviada a cocina, el
// stepper se oculta y el renglón queda de solo lectura (ver
// _ai/docs/decision-log.md).
const isEditable = computed(() => order.status === 'abierta');

const orderStatusLabel: Record<OrderStatus, string> = {
    abierta: 'Abierta',
    enviada_cocina: 'Enviada a cocina',
    lista: 'Lista',
    por_cobrar: 'Por cobrar',
    pagada: 'Pagada',
    cancelada: 'Cancelada',
};

const menuItemById = computed(() => new Map(menuItems.map((item) => [item.id, item])));

const categories = computed(() => {
    const grouped = new Map<string, MenuItem[]>();

    for (const item of menuItems) {
        const items = grouped.get(item.category) ?? [];
        items.push(item);
        grouped.set(item.category, items);
    }

    return Array.from(grouped.entries());
});

const cuentaLines = computed(() =>
    (order.items ?? []).map((orderItem) => ({
        orderItem,
        menuItem: menuItemById.value.get(orderItem.menu_item_id),
        subtotal: Number(orderItem.unit_price) * orderItem.quantity,
    })),
);

const total = computed(() => cuentaLines.value.reduce((sum, line) => sum + line.subtotal, 0));

const canSend = computed(() => isEditable.value && cuentaLines.value.length > 0);

function money(value: number): string {
    return `$${value.toFixed(2)}`;
}

// Mensaje de error genérico (ítem ya no disponible, orden ya enviada,
// etc.) — todos los 422 de dominio de este flujo viajan como
// ValidationException, así que llegan aquí como cualquier error de
// formulario normal de Inertia (ver OrderController).
const page = usePage();
const errorMessage = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;
    const values = Object.values(errors ?? {});

    return values.length > 0 ? values[0] : null;
});

const addingItemId = ref<number | null>(null);

function addItem(item: MenuItem) {
    if (!item.available || addingItemId.value !== null) {
        return;
    }

    addingItemId.value = item.id;
    router.post(
        addItemRoute.url(table.id),
        { menu_item_id: item.id, quantity: 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                addingItemId.value = null;
            },
        },
    );
}

const updatingItemId = ref<number | null>(null);

function adjustQuantity(orderItem: OrderItem, delta: number) {
    if (updatingItemId.value !== null) {
        return;
    }

    updatingItemId.value = orderItem.id;
    router.patch(
        updateItemRoute.url([table.id, orderItem.id]),
        { quantity: Math.max(0, orderItem.quantity + delta) },
        {
            preserveScroll: true,
            onFinish: () => {
                updatingItemId.value = null;
            },
        },
    );
}

const sending = ref(false);

function sendToKitchen() {
    if (!canSend.value) {
        return;
    }

    sending.value = true;
    router.post(
        sendRoute.url(table.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${table.name} · Pedido`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="font-mono text-2xl font-bold tracking-tight text-foreground">
                    {{ table.name }}
                </h1>
                <Badge v-if="order.status !== 'abierta'" variant="secondary">
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
            <!-- Menú -->
            <div class="flex flex-col gap-6">
                <div v-if="menuItems.length === 0" class="rounded-lg border border-dashed py-16 text-center text-sm text-muted-foreground">
                    No hay platillos configurados todavía.
                </div>
                <div v-for="[category, items] in categories" :key="category" class="flex flex-col gap-3">
                    <h2 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        {{ category }}
                    </h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="item in items"
                            :key="item.id"
                            type="button"
                            :disabled="!item.available || addingItemId === item.id"
                            class="flex min-h-[5.5rem] flex-col justify-between gap-2 rounded-lg border-2 border-border bg-card p-3 text-left transition-colors hover:border-primary/50 hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-border disabled:hover:bg-card"
                            @click="addItem(item)"
                        >
                            <span class="text-sm leading-snug font-medium text-foreground">{{ item.name }}</span>
                            <span class="flex items-center justify-between">
                                <span class="font-mono text-sm text-muted-foreground">{{ money(Number(item.price)) }}</span>
                                <Spinner v-if="addingItemId === item.id" class="size-3.5" />
                                <span v-else-if="!item.available" class="text-xs text-muted-foreground">No disponible</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- La Cuenta -->
            <Card class="h-fit lg:sticky lg:top-6">
                <CardHeader>
                    <CardTitle class="font-mono">La Cuenta</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div v-if="cuentaLines.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        Aún no hay platillos en la cuenta. Toca un platillo del menú para agregarlo.
                    </div>

                    <ul v-else class="flex flex-col gap-3">
                        <li v-for="line in cuentaLines" :key="line.orderItem.id" class="flex items-start justify-between gap-2">
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-medium text-foreground">
                                    {{ line.menuItem?.name ?? `Platillo #${line.orderItem.menu_item_id}` }}
                                </span>
                                <span class="font-mono text-xs text-muted-foreground">
                                    {{ money(Number(line.orderItem.unit_price)) }} c/u
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <template v-if="isEditable">
                                    <Button
                                        size="icon"
                                        variant="outline"
                                        class="size-7"
                                        :disabled="updatingItemId === line.orderItem.id"
                                        @click="adjustQuantity(line.orderItem, -1)"
                                    >
                                        <Minus class="size-3.5" />
                                    </Button>
                                    <span class="w-5 text-center font-mono text-sm text-foreground">{{ line.orderItem.quantity }}</span>
                                    <Button
                                        size="icon"
                                        variant="outline"
                                        class="size-7"
                                        :disabled="updatingItemId === line.orderItem.id"
                                        @click="adjustQuantity(line.orderItem, 1)"
                                    >
                                        <Plus class="size-3.5" />
                                    </Button>
                                </template>
                                <span v-else class="font-mono text-sm text-foreground">×{{ line.orderItem.quantity }}</span>

                                <span class="w-14 text-right font-mono text-sm font-medium text-foreground">
                                    {{ money(line.subtotal) }}
                                </span>
                            </div>
                        </li>
                    </ul>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-foreground">Total</span>
                        <span class="font-mono text-lg font-bold text-foreground">{{ money(total) }}</span>
                    </div>

                    <Button v-if="isEditable" size="lg" :disabled="!canSend || sending" @click="sendToKitchen">
                        <Spinner v-if="sending" class="size-4" />
                        {{ sending ? 'Enviando…' : 'Enviar a Cocina' }}
                    </Button>
                    <Badge v-else variant="secondary" class="w-full justify-center py-2">
                        {{ orderStatusLabel[order.status] }} — puedes seguir agregando platillos
                    </Badge>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
