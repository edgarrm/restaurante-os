<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChefHat, LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as cocinaIndex } from '@/routes/cocina';
import { index as mesasIndex } from '@/routes/mesas';
import type { NavItem } from '@/types';

// Nav de restaurante-os (ver _ai/design/screen-inventory.md) — se amplía
// conforme se construyen más pantallas de Fase 03. "Mesas" es el punto de
// entrada real de mesero/admin y "Cocina" el de cocina/admin (no el
// "Dashboard" genérico del starter kit) — filtrado por rol porque
// `role:admin,mesero` y `role:admin,cocina` (routes/tenant.php) devuelven
// 403 si el otro rol intenta entrar.
const page = usePage();
const role = computed(() => page.props.auth.user.role);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (role.value === 'admin' || role.value === 'mesero') {
        items.push({ title: 'Mesas', href: mesasIndex(), icon: LayoutGrid });
    }

    if (role.value === 'admin' || role.value === 'cocina') {
        items.push({ title: 'Cocina', href: cocinaIndex(), icon: ChefHat });
    }

    return items;
});

// El logo enlaza al punto de entrada del rol actual — para cocina eso es
// /cocina, no /mesas (403 si lo intentara).
const homeHref = computed(() => (role.value === 'cocina' ? cocinaIndex() : mesasIndex()));
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
