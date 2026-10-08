<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

final class ConfigureLocalJwtKeys extends Command
{
    protected $signature = 'auth:jwt-keys';

    protected $description = 'Generate and configure local RS256 JWT keys in the ignored .env file';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is available only in local or testing environments.');

            return self::FAILURE;
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Unable to generate the local JWT private key.');
        }

        $details = openssl_pkey_get_details($key);
        $publicKey = is_array($details) ? ($details['key'] ?? null) : null;

        if (! is_string($publicKey) || $publicKey === '') {
            throw new RuntimeException('Unable to derive the local JWT public key.');
        }

        $envPath = base_path('.env');
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $contents = preg_replace('/^AUTH_JWT_PRIVATE_KEY=.*$/m', '', $contents) ?? $contents;
        $contents = preg_replace('/^AUTH_JWT_PUBLIC_KEYS=.*$/m', '', $contents) ?? $contents;
        $publicKeys = json_encode(['schoolos-current' => $publicKey], JSON_THROW_ON_ERROR);
        $contents = rtrim($contents).PHP_EOL
            .'AUTH_JWT_CURRENT_KID=schoolos-current'.PHP_EOL
            .'AUTH_JWT_PRIVATE_KEY="'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $privateKey).'"'.PHP_EOL
            .'AUTH_JWT_PUBLIC_KEYS=\''.str_replace("'", "\\'", $publicKeys).'\''.PHP_EOL;

        if (file_put_contents($envPath, $contents) === false) {
            throw new RuntimeException('Unable to update the local .env file.');
        }

        $this->info('Local RS256 JWT keys generated and configured in .env.');
        $this->warn('The private key is local deployment secret material; do not commit or share .env.');

        return self::SUCCESS;
    }
}
