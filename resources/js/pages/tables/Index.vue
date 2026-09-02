<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
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
import { Spinner } from '@/components/ui/spinner';
import { destroy, index as tablesIndex, store, update } from '@/routes/tables';
import type { Table, TableStatus } from '@/types';

const { tables } = defineProps<{
    tables: Table[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Gestión de Mesas', href: tablesIndex() }],
    },
});

const statusLabel: Record<TableStatus, string> = {
    libre: 'Libre',
    ocupada: 'Ocupada',
    por_cobrar: 'Por cobrar',
};

const statusVariant: Record<TableStatus, 'secondary' | 'outline'> = {
    libre: 'secondary',
    ocupada: 'outline',
    por_cobrar: 'outline',
};

type TableFormFields = {
    name: string;
    capacity: string;
};

const isCreateOpen = ref(false);
const createForm = useForm<TableFormFields>({
    name: '',
    capacity: '',
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

const editingTable = ref<Table | null>(null);
const editForm = useForm<TableFormFields>({
    name: '',
    capacity: '',
});

function openEdit(table: Table) {
    editForm.clearErrors();
    editForm.name = table.name;
    editForm.capacity = String(table.capacity);
    editingTable.value = table;
}

function closeEdit() {
    editingTable.value = null;
}

function submitEdit() {
    if (!editingTable.value) {
        return;
    }

    editForm.patch(update.url(editingTable.value.id), {
        preserveScroll: true,
        onSuccess: closeEdit,
    });
}

// Eliminar sí requiere confirmación (a diferencia de alternar disponibilidad
// en Gestión de Menú): es destructivo e irreversible desde esta pantalla,
// aunque el modelo use soft delete (_ai/specs/gestion-mesas.spec.md).
const deletingTable = ref<Table | null>(null);
const isDeleting = ref(false);
const deleteError = ref<string | null>(null);

function openDelete(table: Table) {
    deleteError.value = null;
    deletingTable.value = table;
}

function closeDelete() {
    deletingTable.value = null;
    deleteError.value = null;
}

function confirmDelete() {
    if (!deletingTable.value) {
        return;
    }

    isDeleting.value = true;
    router.delete(destroy.url(deletingTable.value.id), {
        preserveScroll: true,
        onSuccess: closeDelete,
        // El backend usa abort(422, mensaje) para el caso "mesa con cuenta
        // activa" en vez de ValidationException (ver spec, Edge Cases) — esa
        // respuesta no trae el header X-Inertia, así que Inertia la trata
        // como no-Inertia y no llega un mensaje estructurado aquí. El botón
        // "Eliminar" ya está deshabilitado para mesas `ocupada` (la única
        // combinación que dispara ese bloqueo, ver statusVariant/status
        // arriba), así que este mensaje es un respaldo para el caso residual
        // (carrera con un pedido recién abierto).
        onError: () => {
            deleteError.value =
                'No se pudo eliminar la mesa: probablemente tiene una cuenta activa.';
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Gestión de Mesas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Gestión de Mesas
            </h1>
            <Button @click="openCreate">Nueva mesa</Button>
        </div>

        <div
            v-if="tables.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay mesas configuradas todavía.
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Crea la primera mesa para que el mapa de mesas y la toma de
                pedidos tengan sobre qué operar.
            </p>
            <Button @click="openCreate">Nueva mesa</Button>
        </div>

        <div
            v-else
            class="divide-y divide-border overflow-hidden rounded-lg border"
        >
            <div
                v-for="table in tables"
                :key="table.id"
                class="flex flex-wrap items-center justify-between gap-3 bg-card p-4"
            >
                <div class="flex items-center gap-3">
                    <span class="font-mono font-medium text-foreground">{{
                        table.name
                    }}</span>
                    <Badge :variant="statusVariant[table.status]">
                        {{ statusLabel[table.status] }}
                    </Badge>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">
                        {{ table.capacity }}
                        {{ table.capacity === 1 ? 'persona' : 'personas' }}
                    </span>
                    <Button size="sm" variant="outline" @click="openEdit(table)"
                        >Editar</Button
                    >
                    <Button
                        size="sm"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        :disabled="table.status === 'ocupada'"
                        :title="
                            table.status === 'ocupada'
                                ? 'No se puede eliminar: tiene una cuenta activa.'
                                : undefined
                        "
                        @click="openDelete(table)"
                    >
                        Eliminar
                    </Button>
                </div>
            </div>
        </div>

        <!-- Nueva mesa -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>Nueva mesa</DialogTitle>
                        <DialogDescription>
                            Queda disponible de inmediato en el mapa de mesas
                            con estado libre.
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

                    <div class="grid gap-2">
                        <Label for="create-capacity">Capacidad</Label>
                        <Input
                            id="create-capacity"
                            v-model="createForm.capacity"
                            type="number"
                            step="1"
                            min="1"
                        />
                        <InputError :message="createForm.errors.capacity" />
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
                                    : 'Crear mesa'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Editar mesa -->
        <Dialog
            :open="editingTable !== null"
            @update:open="(value) => !value && closeEdit()"
        >
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitEdit">
                    <DialogHeader>
                        <DialogTitle>Editar mesa</DialogTitle>
                        <DialogDescription>
                            Cambiar nombre o capacidad no afecta ninguna cuenta
                            en curso en esta mesa.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="edit-name">Nombre</Label>
                        <Input
                            id="edit-name"
                            v-model="editForm.name"
                            autocomplete="off"
                        />
                        <InputError :message="editForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-capacity">Capacidad</Label>
                        <Input
                            id="edit-capacity"
                            v-model="editForm.capacity"
                            type="number"
                            step="1"
                            min="1"
                        />
                        <InputError :message="editForm.errors.capacity" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="editForm.processing">
                            <Spinner
                                v-if="editForm.processing"
                                class="size-4"
                            />
                            {{
                                editForm.processing
                                    ? 'Guardando…'
                                    : 'Guardar cambios'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Eliminar mesa -->
        <Dialog
            :open="deletingTable !== null"
            @update:open="(value) => !value && closeDelete()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Eliminar mesa</DialogTitle>
                    <DialogDescription>
                        {{
                            deletingTable
                                ? `¿Eliminar "${deletingTable.name}"? Esta acción no se puede deshacer.`
                                : ''
                        }}
                    </DialogDescription>
                </DialogHeader>

                <InputError :message="deleteError ?? undefined" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary"
                            >Cancelar</Button
                        >
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="isDeleting"
                        @click="confirmDelete"
                    >
                        <Spinner v-if="isDeleting" class="size-4" />
                        {{ isDeleting ? 'Eliminando…' : 'Eliminar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
