<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { cancel, index as reservasIndex, seat, store } from '@/routes/reservas';
import type { Reservation, ReservationStatus, Table } from '@/types';

const { reservations, tables } = defineProps<{
    reservations: Reservation[];
    tables: Table[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reservas', href: reservasIndex() }],
    },
});

// #6 (Calendario) y #7 (Nueva reserva) del inventario original son UNA sola
// pantalla: el backend solo tiene index+store, ambos renderizan
// 'reservas/Index' (ver _ai/design/screen-inventory.md y decision-log.md).
// El formulario de nueva reserva vive en un diálogo, mismo patrón que
// "Nuevo platillo" en menu/Index.vue.

const statusLabel: Record<ReservationStatus, string> = {
    confirmada: 'Confirmada',
    sentada: 'Sentada',
    cancelada: 'Cancelada',
};

const statusVariant: Record<
    ReservationStatus,
    'secondary' | 'default' | 'destructive'
> = {
    confirmada: 'secondary',
    sentada: 'default',
    cancelada: 'destructive',
};

const tablesById = computed(
    () => new Map(tables.map((table) => [table.id, table])),
);

// Transiciones de status (cierre de la brecha #8, ver decision-log.md,
// 2026-08-25): solo `confirmada` puede pasar a `sentada`/`cancelada` (guard
// del servidor en Seat/CancelReservationAction), así que los botones solo se
// muestran para reservas `confirmada` — evita depender solo del guard del
// servidor para la UX. Mismo patrón que `toggleItemAvailability` en
// menu/Index.vue: sin useForm, `router.patch` directo con un guard de
// "una transición a la vez" por reserva.
const transitioningId = ref<number | null>(null);
const transitionError = ref<string | null>(null);

function seatReservation(reservation: Reservation) {
    if (transitioningId.value !== null) {
        return;
    }

    transitioningId.value = reservation.id;
    transitionError.value = null;
    router.patch(
        seat.url(reservation.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                transitionError.value =
                    errors.status ?? 'No se pudo sentar la reserva.';
            },
            onFinish: () => {
                transitioningId.value = null;
            },
        },
    );
}

function cancelReservation(reservation: Reservation) {
    if (transitioningId.value !== null) {
        return;
    }

    transitioningId.value = reservation.id;
    transitionError.value = null;
    router.patch(
        cancel.url(reservation.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                transitionError.value =
                    errors.status ?? 'No se pudo cancelar la reserva.';
            },
            onFinish: () => {
                transitioningId.value = null;
            },
        },
    );
}

function tableLabel(reservation: Reservation): string {
    const table = reservation.table_id
        ? tablesById.value.get(reservation.table_id)
        : null;

    return table ? table.name : 'Sin mesa asignada';
}

// `reserved_at` viaja como ISO en UTC (serialización default de Carbon),
// pero es un valor "naive" de facto: `APP_TIMEZONE=UTC` (config/app.php) y
// el <input type="datetime-local"> del diálogo mandan/guardan la hora tal
// cual la tipeó el mesero, sin conversión de zona horaria. Formatear sin
// forzar `timeZone: 'UTC'` aquí hace que el navegador reinterprete esos
// dígitos en la zona horaria local del viewer, mostrando una hora distinta
// a la que se guardó (bug encontrado en verificación manual: se tipeó
// 8:00 p.m. y la lista mostraba 1:00 p.m. en un navegador con otro huso).
function formatTime(reservedAt: string): string {
    return new Date(reservedAt).toLocaleTimeString('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
    });
}

// Sentinel para "sin asignar": reka-ui/SelectItem no acepta value="" (lo
// trata como "sin selección"). Se convierte a null en transform() antes de
// enviar al backend, donde table_id es nullable.
const UNASSIGNED_TABLE = 'sin-mesa';

type ReservationFormFields = {
    customer_name: string;
    customer_phone: string;
    party_size: number;
    reserved_at: string;
    table_id: string;
};

const isCreateOpen = ref(false);
const createForm = useForm<ReservationFormFields>({
    customer_name: '',
    customer_phone: '',
    party_size: 1,
    reserved_at: '',
    table_id: UNASSIGNED_TABLE,
});

createForm.transform((data) => ({
    ...data,
    table_id: data.table_id === UNASSIGNED_TABLE ? null : Number(data.table_id),
}));

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    isCreateOpen.value = true;
}

function submitCreate() {
    createForm.clearErrors();

    // Chequeo de UX antes de mandar la petición (evita un roundtrip obvio),
    // pero el servidor sigue siendo la fuente de verdad del error — mismo
    // mensaje que _ai/specs/reservas.spec.md, Error States.
    if (
        createForm.reserved_at &&
        new Date(createForm.reserved_at) < new Date()
    ) {
        createForm.setError(
            'reserved_at',
            'La hora de la reserva debe ser futura.',
        );

        return;
    }

    createForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateOpen.value = false;
            createForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Reservas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Reservas de hoy
            </h1>
            <Button @click="openCreate">Nueva reserva</Button>
        </div>

        <p v-if="transitionError" class="text-sm text-destructive">
            {{ transitionError }}
        </p>

        <div
            v-if="reservations.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay reservas para hoy todavía.
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Crea la primera reserva del día para llevar el control de las
                mesas comprometidas.
            </p>
            <Button @click="openCreate">Nueva reserva</Button>
        </div>

        <div
            v-else
            class="divide-y divide-border overflow-hidden rounded-lg border"
        >
            <div
                v-for="reservation in reservations"
                :key="reservation.id"
                class="flex flex-wrap items-center justify-between gap-3 bg-card p-4"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="font-mono text-lg font-semibold text-foreground"
                    >
                        {{ formatTime(reservation.reserved_at) }}
                    </span>
                    <div class="flex flex-col">
                        <span class="font-medium text-foreground">{{
                            reservation.customer_name
                        }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            reservation.customer_phone
                        }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">
                        {{ reservation.party_size }}
                        {{
                            reservation.party_size === 1
                                ? 'persona'
                                : 'personas'
                        }}
                    </span>
                    <span class="text-sm text-muted-foreground">{{
                        tableLabel(reservation)
                    }}</span>
                    <Badge :variant="statusVariant[reservation.status]">
                        {{ statusLabel[reservation.status] }}
                    </Badge>
                    <div
                        v-if="reservation.status === 'confirmada'"
                        class="flex items-center gap-2"
                    >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="transitioningId === reservation.id"
                            @click="seatReservation(reservation)"
                        >
                            Sentar
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="transitioningId === reservation.id"
                            @click="cancelReservation(reservation)"
                        >
                            Cancelar
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nueva reserva -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>Nueva reserva</DialogTitle>
                        <DialogDescription>
                            Queda con status "Confirmada". Asignar mesa es
                            opcional — puede decidirse el día de la reserva.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="create-customer-name"
                            >Nombre del cliente</Label
                        >
                        <Input
                            id="create-customer-name"
                            v-model="createForm.customer_name"
                            autocomplete="off"
                        />
                        <InputError
                            :message="createForm.errors.customer_name"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-customer-phone">Teléfono</Label>
                        <Input
                            id="create-customer-phone"
                            v-model="createForm.customer_phone"
                            autocomplete="off"
                        />
                        <InputError
                            :message="createForm.errors.customer_phone"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="create-party-size">Personas</Label>
                            <Input
                                id="create-party-size"
                                v-model.number="createForm.party_size"
                                type="number"
                                min="1"
                                step="1"
                            />
                            <InputError
                                :message="createForm.errors.party_size"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="create-reserved-at">Fecha y hora</Label>
                            <Input
                                id="create-reserved-at"
                                v-model="createForm.reserved_at"
                                type="datetime-local"
                            />
                            <InputError
                                :message="createForm.errors.reserved_at"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-table">Mesa (opcional)</Label>
                        <Select v-model="createForm.table_id">
                            <SelectTrigger id="create-table" class="w-full">
                                <SelectValue placeholder="Sin asignar" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="UNASSIGNED_TABLE"
                                    >Sin asignar</SelectItem
                                >
                                <SelectItem
                                    v-for="table in tables"
                                    :key="table.id"
                                    :value="String(table.id)"
                                >
                                    {{ table.name }} ({{ table.capacity }}
                                    personas)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.table_id" />
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
                                    : 'Crear reserva'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
