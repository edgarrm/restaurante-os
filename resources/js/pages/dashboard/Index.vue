<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import type { Reservation, ReservationStatus, Table, TableStatus } from '@/types';

const { salesTotal, activeTables, todayReservations } = defineProps<{
    salesTotal: number;
    activeTables: Table[];
    todayReservations: Reservation[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

// Resumen de mesas/pagos/reservas — mismo criterio de tiempo casi real que
// Mapa de Mesas/Cocina (ADR-005, _ai/specs/dashboard-del-dia.spec.md, PASO 0).
usePoll(4000);

function money(value: number): string {
    return `$${value.toFixed(2)}`;
}

const statusLabel: Record<TableStatus, string> = {
    libre: 'Libre',
    ocupada: 'Ocupada',
    por_cobrar: 'Por cobrar',
};

const statusDotClasses: Record<TableStatus, string> = {
    libre: 'bg-status-libre',
    ocupada: 'bg-status-ocupada',
    por_cobrar: 'bg-status-por-cobrar',
};

const reservationStatusLabel: Record<ReservationStatus, string> = {
    confirmada: 'Confirmada',
    sentada: 'Sentada',
    cancelada: 'Cancelada',
};

const reservationStatusVariant: Record<ReservationStatus, 'secondary' | 'default' | 'destructive'> = {
    confirmada: 'secondary',
    sentada: 'default',
    cancelada: 'destructive',
};

// `reserved_at` viaja como ISO en UTC (serialización default de Carbon) pero
// es un valor "naive" de facto (APP_TIMEZONE=UTC, sin conversión de zona
// horaria) — formatear forzando timeZone: 'UTC' evita que el navegador lo
// reinterprete en su huso local (mismo bug/fix ya documentado en
// reservas/Index.vue).
function formatTime(reservedAt: string): string {
    return new Date(reservedAt).toLocaleTimeString('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
    });
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <h1 class="text-2xl font-bold tracking-tight text-foreground">
            Resumen del día
        </h1>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">
                        Ventas de hoy
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="font-mono text-3xl font-bold text-foreground">
                        {{ money(salesTotal) }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">
                        Mesas activas
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="font-mono text-3xl font-bold text-foreground">
                        {{ activeTables.length }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">
                        Reservas de hoy
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="font-mono text-3xl font-bold text-foreground">
                        {{ todayReservations.length }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Mesas activas</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="activeTables.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Todas las mesas están libres.
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="table in activeTables"
                            :key="table.id"
                            class="flex items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <span class="flex items-center gap-2 font-mono font-medium text-foreground">
                                <span class="size-2.5 shrink-0 rounded-full" :class="statusDotClasses[table.status]" />
                                {{ table.name }}
                            </span>
                            <span class="text-sm text-muted-foreground">{{ statusLabel[table.status] }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Reservas de hoy</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="todayReservations.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Sin reservas para hoy.
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="reservation in todayReservations"
                            :key="reservation.id"
                            class="flex items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <div class="flex flex-col gap-0.5">
                                <span class="font-medium text-foreground">{{ reservation.customer_name }}</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ formatTime(reservation.reserved_at) }} · {{ reservation.party_size }}
                                    {{ reservation.party_size === 1 ? 'persona' : 'personas' }} ·
                                    {{ reservation.table?.name ?? 'Sin mesa asignada' }}
                                </span>
                            </div>
                            <Badge :variant="reservationStatusVariant[reservation.status]">
                                {{ reservationStatusLabel[reservation.status] }}
                            </Badge>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
