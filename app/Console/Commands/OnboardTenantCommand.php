<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenants\OnboardTenantAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

#[Signature('tenants:onboard
    {name : Nombre del restaurante}
    {subdomain : Subdominio (o dominio completo) donde va a operar el restaurante}
    {admin-name : Nombre del primer admin}
    {admin-email : Email del primer admin}')]
#[Description('Da de alta un restaurante nuevo: crea su Tenant, su Domain y la primera cuenta admin (_ai/specs/onboarding-tenant.spec.md).')]
class OnboardTenantCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OnboardTenantAction $action): int
    {
        // La contraseña nunca se pide como argumento posicional (quedaría en
        // shell history) ni se imprime ni se loggea — ver F-01/F-04 en
        // _ai/docs/threat-model.md.
        $password = $this->secret('Contraseña del admin');

        try {
            $admin = $action->handle([
                'restaurant_name' => (string) $this->argument('name'),
                'subdomain' => (string) $this->argument('subdomain'),
                'admin_name' => (string) $this->argument('admin-name'),
                'admin_email' => (string) $this->argument('admin-email'),
                'admin_password' => (string) $password,
            ]);
        } catch (ValidationException $e) {
            collect($e->errors())
                ->flatten()
                ->each(fn (string $message) => $this->error($message));

            return self::FAILURE;
        }

        $domain = Domain::where('tenant_id', $admin->tenant_id)->value('domain');

        $this->info('Restaurante creado.');
        $this->line("Subdominio: {$domain}");
        $this->line("Admin: {$admin->email}");
        $this->comment('Comunica la contraseña al admin por un canal seguro — este comando no la muestra ni la registra.');

        return self::SUCCESS;
    }
}
