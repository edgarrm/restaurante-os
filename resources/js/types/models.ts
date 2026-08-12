// Tipos del dominio de restaurante (ver _ai/docs/data-model.md y
// _ai/docs/api-contract.yaml). Un tipo por modelo Eloquent expuesto a
// Inertia — se amplía conforme se construyen más pantallas.

export type TableStatus = 'libre' | 'ocupada' | 'por_cobrar';

export interface Table {
    id: number;
    name: string;
    capacity: number;
    status: TableStatus;
}

export interface MenuItem {
    id: number;
    name: string;
    category: string;
    price: string;
    available: boolean;
}

export type OrderStatus = 'abierta' | 'enviada_cocina' | 'lista' | 'por_cobrar' | 'pagada' | 'cancelada';

export type OrderItemStatus = 'pendiente' | 'preparando' | 'listo' | 'servido';

export interface OrderItem {
    id: number;
    order_id: number;
    menu_item_id: number;
    quantity: number;
    unit_price: string;
    status: OrderItemStatus;
}

export interface Order {
    id: number;
    table_id: number;
    opened_by: number;
    status: OrderStatus;
    opened_at: string;
    closed_at: string | null;
    items?: OrderItem[];
}
