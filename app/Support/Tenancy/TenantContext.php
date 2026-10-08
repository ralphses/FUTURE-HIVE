<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Contexts\Identity\Application\DTOs\SchoolContext;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use Closure;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;

final readonly class TenantContext
{
    public function __construct(
        public int $schoolId,
        public string $schoolPublicId,
        public ?int $membershipId = null,
        public ?int $identityId = null,
    ) {}

    public static function fromSchoolContext(SchoolContext $context): self
    {
        return new self(
            schoolId: (int) $context->membership->school_id,
            schoolPublicId: (string) $context->membership->school->public_id,
            membershipId: (int) $context->membership->id,
            identityId: (int) $context->membership->user_id,
        );
    }

    public static function fromMembership(SchoolMembership $membership): self
    {
        if ($membership->status !== 'active' || $membership->school->status !== 'active') {
            throw new InvalidArgumentException('An active school membership is required.');
        }

        return new self(
            schoolId: (int) $membership->school_id,
            schoolPublicId: (string) $membership->school->public_id,
            membershipId: (int) $membership->id,
            identityId: (int) $membership->user_id,
        );
    }

    public static function forSchool(int $schoolId, string $schoolPublicId): self
    {
        return new self($schoolId, $schoolPublicId);
    }

    public static function current(): ?self
    {
        $context = Context::getHidden('tenant_context');

        return $context instanceof self ? $context : null;
    }

    public static function require(): self
    {
        return self::current() ?? throw new MissingTenantContext;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function runInternal(self $context, string $reason, Closure $callback): mixed
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for internal tenant execution.');
        }

        $previous = self::current();
        Context::addHidden('tenant_context', $context);

        try {
            return $callback();
        } finally {
            if ($previous instanceof self) {
                Context::addHidden('tenant_context', $previous);
            } else {
                Context::forgetHidden('tenant_context');
            }
        }
    }
}
