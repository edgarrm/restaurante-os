<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { show as cobroShow } from '@/routes/cobro';
import { index as mesasIndex } from '@/routes/mesas';
import { show as pedidoShow } from '@/routes/pedido';
import { index as tablesIndex } from '@/routes/tables';
import type { Table, TableStatus } from '@/types';

const { tables } = defineProps<{
    tables: Table[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mesas', href: mesasIndex() }],
    },
});

// Sondeo cada 4s (ver ADR-005 y _ai/specs/mapa-de-mesas.spec.md, Happy
// Path #3) — un fallo de red se reintenta en silencio el siguiente ciclo,
// sin manejo especial (comportamiento por defecto de usePoll).
usePoll(4000);

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const statusLabel: Record<TableStatus, string> = {
    libre: 'Libre',
    ocupada: 'Ocupada',
    por_cobrar: 'Por cobrar',
};

const statusClasses: Record<TableStatus, string> = {
    libre: 'border-status-libre/40 bg-status-libre/10',
    ocupada: 'border-status-ocupada/40 bg-status-ocupada/10',
    por_cobrar: 'border-status-por-cobrar/50 bg-status-por-cobrar/10',
};

const statusDotClasses: Record<TableStatus, string> = {
    libre: 'bg-status-libre',
    ocupada: 'bg-status-ocupada',
    por_cobrar: 'bg-status-por-cobrar',
};

/**
 * Mesa libre/ocupada → toma de pedido; mesa por_cobrar → cobro (ver
 * _ai/specs/mapa-de-mesas.spec.md, Happy Path #4-5).
 */
function destinationFor(table: Table) {
    return table.status === 'por_cobrar' ? cobroShow(table.id) : pedidoShow(table.id);
}
</script>

<template>
    <Head title="Mesas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Mapa de Mesas
            </h1>
        </div>

        <div
            v-if="tables.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay mesas configuradas todavía.
            </p>
            <template v-if="isAdmin">
                <p class="max-w-sm text-sm text-muted-foreground">
                    Crea al menos una mesa en Gestión de Mesas para empezar a
                    operar.
                </p>
                <Button as-child>
                    <Link :href="tablesIndex()">Ir a Gestión de Mesas</Link>
                </Button>
            </template>
            <p v-else class="max-w-sm text-sm text-muted-foreground">
                Pide a un admin que configure las mesas del restaurante.
            </p>
        </div>

        <div
            v-else
            class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
        >
            <Link
                v-for="table in tables"
                :key="table.id"
                :href="destinationFor(table)"
                class="flex min-h-[9rem] flex-col justify-between gap-3 rounded-lg border-2 p-4 text-left transition-colors hover:brightness-95"
                :class="statusClasses[table.status]"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="font-mono text-lg font-semibold text-foreground">
                        {{ table.name }}
                    </span>
                    <span
                        class="mt-1 size-3 shrink-0 rounded-full"
                        :class="statusDotClasses[table.status]"
                    />
                </div>
                <div class="flex flex-col gap-1 text-sm">
                    <span class="whitespace-nowrap font-medium text-foreground">
                        {{ statusLabel[table.status] }}
                    </span>
                    <span class="whitespace-nowrap text-muted-foreground">
                        {{ table.capacity }} {{ table.capacity === 1 ? 'persona' : 'personas' }}
                    </span>
                </div>
            </Link>
        </div>
    </div>
</template>
