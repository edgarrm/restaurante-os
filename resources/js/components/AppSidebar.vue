<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    CalendarClock,
    ChefHat,
    LayoutDashboard,
    LayoutGrid,
    Table2,
    Users,
    UtensilsCrossed,
} from '@lucide/vue';
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
import { dashboard } from '@/routes';
import { index as cocinaIndex } from '@/routes/cocina';
import { index as inventarioIndex } from '@/routes/inventario';
import { index as menuIndex } from '@/routes/menu';
import { index as mesasIndex } from '@/routes/mesas';
import { index as reservasIndex } from '@/routes/reservas';
import { index as staffIndex } from '@/routes/staff';
import { index as tablesIndex } from '@/routes/tables';
import type { NavItem } from '@/types';

// Nav de restaurante-os (ver _ai/design/screen-inventory.md) — se amplía
// conforme se construyen más pantallas de Fase 03. Punto de entrada real
// post-login por rol (ver App\Http\Responses\LoginResponse): admin →
// Dashboard, mesero → Mesas, cocina → Cocina — filtrado por rol porque
// `role:admin`/`role:admin,mesero`/`role:admin,cocina` (routes/tenant.php)
// devuelven 403 si otro rol intenta entrar.
const page = usePage();
const role = computed(() => page.props.auth.user.role);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (role.value === 'admin' || role.value === 'mesero') {
        items.push({ title: 'Mesas', href: mesasIndex(), icon: LayoutGrid });
        items.push({
            title: 'Reservas',
            href: reservasIndex(),
            icon: CalendarClock,
        });
    }

    if (role.value === 'admin' || role.value === 'cocina') {
        items.push({ title: 'Cocina', href: cocinaIndex(), icon: ChefHat });
    }

    if (role.value === 'admin') {
        items.push({
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutDashboard,
        });
        items.push({ title: 'Menú', href: menuIndex(), icon: UtensilsCrossed });
        items.push({ title: 'Staff', href: staffIndex(), icon: Users });
        items.push({
            title: 'Gestión de Mesas',
            href: tablesIndex(),
            icon: Table2,
        });
        items.push({
            title: 'Inventario',
            href: inventarioIndex(),
            icon: Boxes,
        });
    }

    return items;
});

// El logo enlaza al punto de entrada del rol actual — mismo destino que
// App\Http\Responses\LoginResponse calcula tras el login.
const homeHref = computed(() => {
    if (role.value === 'admin') {
        return dashboard();
    }

    return role.value === 'cocina' ? cocinaIndex() : mesasIndex();
});
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
