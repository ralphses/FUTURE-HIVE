<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Services;

use App\Contexts\Identity\Domain\Enums\ContactType;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class ContactCanonicalizer
{
    public function __construct(private readonly PhoneNumberUtil $phoneNumberUtil) {}

    public function canonicalize(ContactType $type, string $value): string
    {
        return match ($type) {
            ContactType::Email => $this->email($value),
            ContactType::Phone => $this->phone($value),
        };
    }

    private function email(string $value): string
    {
        $canonical = mb_strtolower(trim($value));

        if (filter_var($canonical, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email contact is invalid.');
        }

        return $canonical;
    }

    private function phone(string $value): string
    {
        try {
            $number = $this->phoneNumberUtil->parse(trim($value), null);
        } catch (NumberParseException) {
            throw new InvalidArgumentException('The phone contact is invalid.');
        }

        if (! $this->phoneNumberUtil->isValidNumber($number)) {
            throw new InvalidArgumentException('The phone contact is invalid.');
        }

        return $this->phoneNumberUtil->format($number, PhoneNumberFormat::E164);
    }
}
