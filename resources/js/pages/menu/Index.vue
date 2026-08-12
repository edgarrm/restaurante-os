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
import { Spinner } from '@/components/ui/spinner';
import { index as menuIndex, store, toggleAvailability, update } from '@/routes/menu';
import type { MenuItem } from '@/types';

const { menuItems } = defineProps<{
    menuItems: MenuItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Menú', href: menuIndex() }],
    },
});

const categories = computed(() => {
    const grouped = new Map<string, MenuItem[]>();

    for (const item of menuItems) {
        const items = grouped.get(item.category) ?? [];
        items.push(item);
        grouped.set(item.category, items);
    }

    return Array.from(grouped.entries());
});

// Datalist de categorías ya usadas, para mitigar (sin normalizar del todo)
// el riesgo conocido de _ai/specs/gestion-menu.spec.md: "categoría es texto
// libre, dos platillos pueden usar categorías con distinta capitalización".
const existingCategories = computed(() => Array.from(new Set(menuItems.map((item) => item.category))).sort());

function money(value: string | number): string {
    return `$${Number(value).toFixed(2)}`;
}

type MenuItemFormFields = {
    name: string;
    category: string;
    price: string;
};

const isCreateOpen = ref(false);
const createForm = useForm<MenuItemFormFields>({
    name: '',
    category: '',
    price: '',
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

const editingItem = ref<MenuItem | null>(null);
const editForm = useForm<MenuItemFormFields>({
    name: '',
    category: '',
    price: '',
});

function openEdit(item: MenuItem) {
    editForm.clearErrors();
    editForm.name = item.name;
    editForm.category = item.category;
    editForm.price = item.price;
    editingItem.value = item;
}

function closeEdit() {
    editingItem.value = null;
}

function submitEdit() {
    if (!editingItem.value) {
        return;
    }

    editForm.patch(update.url(editingItem.value.id), {
        preserveScroll: true,
        onSuccess: closeEdit,
    });
}

// Sin endpoint dedicado para esto: alternar disponibilidad es reversible y
// de un solo tap (ver Happy Path del spec), así que no pasa por useForm ni
// requiere confirmación — mismo patrón que adjustQuantity en Pedido.vue.
const togglingId = ref<number | null>(null);

function toggleItemAvailability(item: MenuItem) {
    if (togglingId.value !== null) {
        return;
    }

    togglingId.value = item.id;
    router.patch(
        toggleAvailability.url(item.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                togglingId.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Gestión de Menú" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                Gestión de Menú
            </h1>
            <Button @click="openCreate">Nuevo platillo</Button>
        </div>

        <div
            v-if="menuItems.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-lg border border-dashed py-24 text-center"
        >
            <p class="text-lg font-medium text-foreground">
                No hay platillos configurados todavía.
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Crea el primer platillo para que Toma de Pedido tenga qué ofrecer.
            </p>
            <Button @click="openCreate">Nuevo platillo</Button>
        </div>

        <div v-else class="flex flex-col gap-6">
            <div v-for="[category, items] in categories" :key="category" class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                    {{ category }}
                </h2>
                <div class="divide-y divide-border overflow-hidden rounded-lg border">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 bg-card p-4"
                    >
                        <div class="flex items-center gap-3">
                            <span class="font-medium text-foreground">{{ item.name }}</span>
                            <Badge :variant="item.available ? 'secondary' : 'outline'">
                                {{ item.available ? 'Disponible' : 'No disponible' }}
                            </Badge>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-sm text-muted-foreground">{{ money(item.price) }}</span>
                            <Button size="sm" variant="outline" @click="openEdit(item)">Editar</Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                :disabled="togglingId === item.id"
                                @click="toggleItemAvailability(item)"
                            >
                                <Spinner v-if="togglingId === item.id" class="size-3.5" />
                                <span v-else>{{ item.available ? 'Desactivar' : 'Activar' }}</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <datalist id="menu-categories">
            <option v-for="category in existingCategories" :key="category" :value="category" />
        </datalist>

        <!-- Nuevo platillo -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>Nuevo platillo</DialogTitle>
                        <DialogDescription>
                            Queda disponible de inmediato para Toma de Pedido.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="create-name">Nombre</Label>
                        <Input id="create-name" v-model="createForm.name" autocomplete="off" />
                        <InputError :message="createForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-category">Categoría</Label>
                        <Input
                            id="create-category"
                            v-model="createForm.category"
                            autocomplete="off"
                            list="menu-categories"
                        />
                        <InputError :message="createForm.errors.category" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-price">Precio</Label>
                        <Input id="create-price" v-model="createForm.price" type="number" step="0.01" min="0.01" />
                        <InputError :message="createForm.errors.price" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="createForm.processing">
                            <Spinner v-if="createForm.processing" class="size-4" />
                            {{ createForm.processing ? 'Guardando…' : 'Crear platillo' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Editar platillo -->
        <Dialog :open="editingItem !== null" @update:open="(value) => !value && closeEdit()">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="submitEdit">
                    <DialogHeader>
                        <DialogTitle>Editar platillo</DialogTitle>
                        <DialogDescription>
                            Cambiar el precio no afecta cuentas ya abiertas con este platillo.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="edit-name">Nombre</Label>
                        <Input id="edit-name" v-model="editForm.name" autocomplete="off" />
                        <InputError :message="editForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-category">Categoría</Label>
                        <Input
                            id="edit-category"
                            v-model="editForm.category"
                            autocomplete="off"
                            list="menu-categories"
                        />
                        <InputError :message="editForm.errors.category" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-price">Precio</Label>
                        <Input id="edit-price" v-model="editForm.price" type="number" step="0.01" min="0.01" />
                        <InputError :message="editForm.errors.price" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="editForm.processing">
                            <Spinner v-if="editForm.processing" class="size-4" />
                            {{ editForm.processing ? 'Guardando…' : 'Guardar cambios' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
