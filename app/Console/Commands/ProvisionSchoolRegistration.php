<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contexts\Identity\Application\Actions\ProvisionSchoolRegistrationAction;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionSchoolRegistration extends Command
{
    protected $signature = 'school-registrations:provision {registration : Registration public identifier}';

    protected $description = 'Provision a verified school registration through the internal workflow.';

    public function handle(ProvisionSchoolRegistrationAction $provision): int
    {
        try {
            $result = $provision->execute((string) $this->argument('registration'));
            $this->info('School registration provisioned.');
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('School registration provisioning failed.');

            return self::FAILURE;
        }
    }
}
