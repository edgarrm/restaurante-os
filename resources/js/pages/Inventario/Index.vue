<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { adjust, index as inventarioIndex, store } from '@/routes/inventario';
import type { InventoryItem, InventoryMovementType } from '@/types';

const { items } = defineProps<{
    items: InventoryItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inventario', href: inventarioIndex() }],
    },
});

// Umbral y cantidad comparados como número — ambos viajan como string
// (decimal) desde el backend (ver _ai/docs/data-model.md,
// InventoryItem.quantity_on_hand/low_stock_threshold decimal(10,3)).
function isOutOfStock(item: InventoryItem): boolean {
    return Number(item.quantity_on_hand) <= 0;
}

function isLowStock(item: InventoryItem): boolean {
    return (
        !isOutOfStock(item) &&
        Number(item.quantity_on_hand) <= Number(item.low_stock_threshold)
    );
}

// Paleta semántica del proyecto (resources/css/app.css): ámbar=alerta,
// rojo=crítico (ver _ai/specs/inventario.spec.md, Edge Cases). Reutiliza los
// tokens de status ya definidos para Mesas (--color-status-ocupada) en vez
// de introducir nuevos.
function rowClasses(item: InventoryItem): string {
    if (isOutOfStock(item)) {
        return 'border-destructive/50 bg-destructive/10';
    }

    if (isLowStock(item)) {
        return 'border-status-ocupada/50 bg-status-ocupada/10';
    }

    return '';
}

function quantityLabel(value: string): string {
    return Number(value).toLocaleString('es-MX', { maximumFractionDigits: 3 });
}

// Nuevo insumo (US-5.1) — endpoint agregado en PASO 0 del spec, gap de
// cobertura del contrato original (mismo criterio que "Nueva mesa").
type CreateItemFormFields = {
    name: string;
    unit: string;
    low_stock_threshold: number;
    quantity_on_hand: number;
};

const isCreateOpen = ref(false);
const createForm = useForm<CreateItemFormFields>({
    name: '',
    unit: '',
    low_stock_threshold: 0,
    quantity_on_hand: 0,
});

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    isCreateOpen.value = true;
}

function submitCreate() {
    createForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateOpen.value = false;
            createForm.reset();
        },
    });
}

// Registrar movimiento (US-5.2) — diálogo dentro del índice, no una ruta
// separada (ver api-contract.yaml y PASO 0 del spec).
type MovementFormFields = {
    type: InventoryMovementType;
    quantity: number;
    note: string;
};

const adjustingItem = ref<InventoryItem | null>(null);
const movementForm = useForm<MovementFormFields>({
    type: 'entrada',
    quantity: 0,
    note: '',
});

function openAdjust(item: InventoryItem) {
    movementForm.reset();
    movementForm.clearErrors();
    adjustingItem.value = item;
}

function closeAdjust() {
    adjustingItem.value = null;
}

function submitAdjust() {
    if (!adjustingItem.value) {
        return;
    }

    movementForm.post(adjust.url(adjustingItem.value.id), {
        preserveScroll: true,
        onSuccess: closeAdjust,
    });
}
</script>

<template>
    <Head title="Inventario" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Inventario
            </h1>
            <Button @click="openCreate">Nuevo insumo</Button>
        </div>

        <div
            v-if="items.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay insumos registrados todavía.
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Crea el primer insumo para empezar a llevar el conteo de stock.
            </p>
            <Button @click="openCreate">Nuevo insumo</Button>
        </div>

        <div
            v-else
            class="divide-y divide-border overflow-hidden rounded-lg border"
        >
            <div
                v-for="item in items"
                :key="item.id"
                class="flex flex-wrap items-center justify-between gap-3 border-l-4 bg-card p-4"
                :class="rowClasses(item)"
            >
                <div class="flex flex-col">
                    <span class="font-medium text-foreground">{{
                        item.name
                    }}</span>
                    <span class="text-sm text-muted-foreground"
                        >Umbral de alerta:
                        {{ quantityLabel(item.low_stock_threshold) }}
                        {{ item.unit }}</span
                    >
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="font-mono text-lg font-semibold text-foreground"
                    >
                        {{ quantityLabel(item.quantity_on_hand) }}
                        {{ item.unit }}
                    </span>
                    <Badge v-if="isOutOfStock(item)" variant="destructive"
                        >Sin stock</Badge
                    >
                    <Badge
                        v-else-if="isLowStock(item)"
                        class="border-status-ocupada bg-status-ocupada/20 text-foreground"
                    >
                        Bajo el umbral
                    </Badge>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openAdjust(item)"
                        >Registrar movimiento</Button
                    >
                </div>
            </div>
        </div>

        <!-- Nuevo insumo -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>Nuevo insumo</DialogTitle>
                        <DialogDescription>
                            Queda disponible de inmediato en la lista de
                            inventario.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="create-name">Nombre</Label>
                        <Input
                            id="create-name"
                            v-model="createForm.name"
                            autocomplete="off"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="create-unit">Unidad</Label>
                            <Input
                                id="create-unit"
                                v-model="createForm.unit"
                                autocomplete="off"
                                placeholder="kg, l, unidad…"
                            />
                            <InputError :message="createForm.errors.unit" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="create-threshold"
                                >Umbral de alerta</Label
                            >
                            <Input
                                id="create-threshold"
                                v-model.number="createForm.low_stock_threshold"
                                type="number"
                                step="0.001"
                                min="0"
                            />
                            <InputError
                                :message="createForm.errors.low_stock_threshold"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-quantity">Cantidad inicial</Label>
                        <Input
                            id="create-quantity"
                            v-model.number="createForm.quantity_on_hand"
                            type="number"
                            step="0.001"
                            min="0"
                        />
                        <InputError
                            :message="createForm.errors.quantity_on_hand"
                        />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="createForm.processing">
                            <Spinner
                                v-if="createForm.processing"
                                class="size-4"
                            />
                            {{
                                createForm.processing
                                    ? 'Guardando…'
                                    : 'Crear insumo'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Registrar movimiento -->
        <Dialog
            :open="adjustingItem !== null"
            @update:open="(value) => !value && closeAdjust()"
        >
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitAdjust">
                    <DialogHeader>
                        <DialogTitle>Registrar movimiento</DialogTitle>
                        <DialogDescription>
                            {{ adjustingItem?.name }} — cantidad actual:
                            {{
                                adjustingItem
                                    ? quantityLabel(
                                          adjustingItem.quantity_on_hand,
                                      )
                                    : ''
                            }}
                            {{ adjustingItem?.unit }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="movement-type">Tipo</Label>
                        <Select v-model="movementForm.type">
                            <SelectTrigger id="movement-type" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="entrada">Entrada</SelectItem>
                                <SelectItem value="salida">Salida</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="movementForm.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="movement-quantity">Cantidad</Label>
                        <Input
                            id="movement-quantity"
                            v-model.number="movementForm.quantity"
                            type="number"
                            step="0.001"
                            min="0.001"
                        />
                        <InputError :message="movementForm.errors.quantity" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="movement-note">Nota (opcional)</Label>
                        <Input
                            id="movement-note"
                            v-model="movementForm.note"
                            autocomplete="off"
                            placeholder="Ej. Compra a proveedor, merma…"
                        />
                        <InputError :message="movementForm.errors.note" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="movementForm.processing"
                        >
                            <Spinner
                                v-if="movementForm.processing"
                                class="size-4"
                            />
                            {{
                                movementForm.processing
                                    ? 'Guardando…'
                                    : 'Registrar'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
