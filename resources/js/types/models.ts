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
