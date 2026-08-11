<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Stancl\Tenancy\Database\Models\Domain;

class OnboardTenantAction
{
    /**
     * Da de alta un restaurante nuevo en el SaaS: crea su Tenant, su Domain
     * (subdominio) y la primera cuenta admin, todo en una sola transacción —
     * o se crean los tres, o ninguno (_ai/specs/onboarding-tenant.spec.md).
     *
     * F-04 (_ai/docs/threat-model.md): `role` y `tenant_id` nunca llegan por
     * mass assignment — se asignan explícitamente aquí, nunca vía
     * `User::create($data)`.
     *
     * @param  array{restaurant_name: string, subdomain: string, admin_name: string, admin_email: string, admin_password: string}  $data
     */
    public function handle(array $data): User
    {
        $subdomain = mb_strtolower(trim($data['subdomain']));

        $validator = Validator::make(
            [
                'restaurant_name' => $data['restaurant_name'],
                'subdomain' => $subdomain,
                'admin_name' => $data['admin_name'],
                'admin_email' => $data['admin_email'],
            ],
            [
                'restaurant_name' => ['required', 'string'],
                'subdomain' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/'],
                'admin_name' => ['required', 'string'],
                'admin_email' => ['required', 'email'],
            ],
            [
                'restaurant_name.required' => 'El restaurante necesita un nombre.',
                'subdomain.regex' => 'Ese subdominio tiene caracteres no válidos para DNS.',
            ]
        );

        $validator->after(function ($validator) use ($subdomain) {
            if (Domain::where('domain', $subdomain)->exists()) {
                $validator->errors()->add('subdomain', 'Ese subdominio ya está en uso.');
            }
        });

        $validator->validate();

        return DB::transaction(function () use ($data, $subdomain) {
            $tenant = Tenant::create(['name' => $data['restaurant_name']]);

            Domain::create([
                'tenant_id' => $tenant->getTenantKey(),
                'domain' => $subdomain,
            ]);

            $admin = new User;
            $admin->name = $data['admin_name'];
            $admin->email = $data['admin_email'];
            $admin->password = $data['admin_password'];
            $admin->tenant_id = $tenant->getTenantKey();
            $admin->role = Role::Admin;
            $admin->save();

            return $admin;
        });
    }
}
