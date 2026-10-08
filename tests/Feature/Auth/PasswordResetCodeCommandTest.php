<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contexts\Identity\Domain\Models\PasswordResetChallenge;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PasswordResetCodeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_command_displays_a_code_without_plaintext_persistence(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update([
            'canonical_value' => 'test@example.com',
            'verified_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('auth:password-reset-code', ['login' => 'test@example.com']));

        $output = Artisan::output();
        self::assertMatchesRegularExpression('/Password reset code: ([0-9]{6})/', $output, $output);
        preg_match('/Password reset code: ([0-9]{6})/', $output, $matches);
        $code = (string) ($matches[1] ?? '');
        $challenge = PasswordResetChallenge::query()->latest('id')->firstOrFail();

        self::assertSame(hash('sha256', $code), $challenge->getRawOriginal('code_hash'));
        self::assertStringNotContainsString($code, (string) $challenge->getRawOriginal('code_hash'));
    }

    public function test_command_rejects_unknown_contacts(): void
    {
        self::assertSame(1, Artisan::call('auth:password-reset-code', ['login' => 'unknown@example.com']));
        self::assertStringContainsString('No verified recoverable contact was found.', Artisan::output());
    }
}
