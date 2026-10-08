<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contexts\Identity\Application\Actions\PasswordRecoveryAction;
use Illuminate\Console\Command;

final class IssueDevelopmentPasswordResetCode extends Command
{
    protected $signature = 'auth:password-reset-code {login : Verified email address or international phone number}';

    protected $description = 'Issue a password-reset code for local development without logging it';

    public function handle(PasswordRecoveryAction $recovery): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is available only in local or testing environments.');

            return self::FAILURE;
        }

        $code = $recovery->issueForDevelopment((string) $this->argument('login'));

        if ($code === null) {
            $this->error('No verified recoverable contact was found.');

            return self::FAILURE;
        }

        $this->line('Password reset code: '.$code);
        $this->warn('Development-only terminal output. The code was not logged or stored in plaintext.');

        return self::SUCCESS;
    }
}
