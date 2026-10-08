<?php

declare(strict_types=1);

namespace App\Support\Contacts;

interface ContactNormalizer
{
    public function normalize(string $contact): CanonicalContact;
}
