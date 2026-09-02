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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { deactivate, index as staffIndex, store, update } from '@/routes/staff';
import type { User } from '@/types';

const { staff } = defineProps<{
    staff: User[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff', href: staffIndex() }],
    },
});

// --- Nueva cuenta ---

type CreateFields = {
    name: string;
    email: string;
    password: string;
    role: string;
};

const isCreateOpen = ref(false);
const createForm = useForm<CreateFields>({
    name: '',
    email: '',
    password: '',
    role: 'mesero',
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

// --- Editar rol ---

type EditFields = {
    role: string;
};

const editingMember = ref<User | null>(null);
const editForm = useForm<EditFields>({
    role: 'mesero',
});

function openEdit(member: User) {
    editForm.clearErrors();
    editForm.role = member.role;
    editingMember.value = member;
}

function closeEdit() {
    editingMember.value = null;
}

function submitEdit() {
    if (!editingMember.value) {
        return;
    }

    editForm.patch(update.url(editingMember.value.id), {
        preserveScroll: true,
        onSuccess: closeEdit,
    });
}

// --- Desactivar ---
// No es reversible desde la UI (no existe endpoint de reactivar en el MVP),
// así que requiere diálogo de confirmación — mismo criterio que acciones
// destructivas en otras pantallas.

const deactivatingMember = ref<User | null>(null);
const isDeactivating = ref(false);

function openDeactivate(member: User) {
    deactivatingMember.value = member;
}

function closeDeactivate() {
    deactivatingMember.value = null;
}

function confirmDeactivate() {
    if (!deactivatingMember.value) {
        return;
    }

    isDeactivating.value = true;
    router.patch(
        deactivate.url(deactivatingMember.value.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isDeactivating.value = false;
                deactivatingMember.value = null;
            },
        },
    );
}

function roleLabel(role: string): string {
    return role === 'cocina' ? 'Cocina' : 'Mesero';
}
</script>

<template>
    <Head title="Gestión de Staff" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Gestión de Staff
            </h1>
            <Button @click="openCreate">Nueva cuenta</Button>
        </div>

        <div
            v-if="staff.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay cuentas de staff todavía.
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Crea la primera cuenta para que tu equipo pueda iniciar sesión.
            </p>
            <Button @click="openCreate">Nueva cuenta</Button>
        </div>

        <div
            v-else
            class="divide-y divide-border overflow-hidden rounded-lg border"
        >
            <div
                v-for="member in staff"
                :key="member.id"
                class="flex flex-wrap items-center justify-between gap-3 bg-card p-4"
            >
                <div class="flex flex-col gap-0.5">
                    <span class="font-medium text-foreground">{{
                        member.name
                    }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        member.email
                    }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <Badge variant="secondary">{{
                        roleLabel(member.role)
                    }}</Badge>
                    <Badge
                        :variant="member.is_active ? 'secondary' : 'outline'"
                    >
                        {{ member.is_active ? 'Activo' : 'Inactivo' }}
                    </Badge>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openEdit(member)"
                    >
                        Editar rol
                    </Button>
                    <Button
                        v-if="member.is_active"
                        size="sm"
                        variant="ghost"
                        @click="openDeactivate(member)"
                    >
                        Desactivar
                    </Button>
                </div>
            </div>
        </div>

        <!-- Nueva cuenta -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>Nueva cuenta</DialogTitle>
                        <DialogDescription>
                            La cuenta puede iniciar sesión de inmediato con
                            estas credenciales.
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
                        <Label for="create-email">Correo</Label>
                        <Input
                            id="create-email"
                            v-model="createForm.email"
                            type="email"
                            autocomplete="off"
                        />
                        <InputError :message="createForm.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-password">Contraseña temporal</Label>
                        <Input
                            id="create-password"
                            v-model="createForm.password"
                            type="password"
                            autocomplete="new-password"
                        />
                        <InputError :message="createForm.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-role">Rol</Label>
                        <Select v-model="createForm.role">
                            <SelectTrigger id="create-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="mesero">Mesero</SelectItem>
                                <SelectItem value="cocina">Cocina</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.role" />
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
                                    : 'Crear cuenta'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Editar rol -->
        <Dialog
            :open="editingMember !== null"
            @update:open="(value) => !value && closeEdit()"
        >
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitEdit">
                    <DialogHeader>
                        <DialogTitle>Editar rol</DialogTitle>
                        <DialogDescription>
                            El cambio aplica en la siguiente request de
                            {{ editingMember?.name }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="edit-role">Rol</Label>
                        <Select v-model="editForm.role">
                            <SelectTrigger id="edit-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="mesero">Mesero</SelectItem>
                                <SelectItem value="cocina">Cocina</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="editForm.errors.role" />
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

        <!-- Confirmar desactivación -->
        <Dialog
            :open="deactivatingMember !== null"
            @update:open="(value) => !value && closeDeactivate()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Desactivar cuenta?</DialogTitle>
                    <DialogDescription>
                        {{ deactivatingMember?.name }} no podrá iniciar sesión.
                        El historial de órdenes se conserva intacto.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="closeDeactivate"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="isDeactivating"
                        @click="confirmDeactivate"
                    >
                        <Spinner v-if="isDeactivating" class="size-4" />
                        {{ isDeactivating ? 'Desactivando…' : 'Desactivar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
