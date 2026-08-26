import type { PaymentMethod } from '@/types';

export type PaymentMethodOption = { value: PaymentMethod; label: string };

// Única fuente de verdad de las opciones de "Método de pago" — antes
// duplicada entre los dos modos de split de mesas/Cobro.vue ("Por
// monto"/"Por ítems", REDEV-29). Deuda técnica documentada en
// _ai/CONTEXT.md, resuelta extrayendo `PaymentMethodSelector.vue`.
export const paymentMethodOptions: PaymentMethodOption[] = [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'transferencia', label: 'Transferencia' },
];
