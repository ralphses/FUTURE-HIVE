<?php

declare(strict_types=1);

namespace App\Support\Contacts;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use InvalidArgumentException;

final class IdentityContactNormalizer implements ContactNormalizer
{
    public function __construct(private readonly ContactCanonicalizer $canonicalizer) {}

    public function normalize(string $contact): CanonicalContact
    {
        $trimmed = trim($contact);

        try {
            return new CanonicalContact(
                'email',
                $this->canonicalizer->canonicalize(ContactType::Email, $trimmed),
            );
        } catch (InvalidArgumentException) {
            try {
                return new CanonicalContact(
                    'phone',
                    $this->canonicalizer->canonicalize(ContactType::Phone, $trimmed),
                );
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('The contact is invalid.', previous: $exception);
            }
        }
    }
}
