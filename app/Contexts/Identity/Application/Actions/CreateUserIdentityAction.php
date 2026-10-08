<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\CreateUserIdentityData;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class CreateUserIdentityAction
{
    public function __construct(private readonly ContactCanonicalizer $canonicalizer) {}

    public function execute(CreateUserIdentityData $data): UserIdentity
    {
        if ($data->contacts === []) {
            throw new InvalidArgumentException('An identity requires at least one active contact.');
        }

        return DB::transaction(function () use ($data): UserIdentity {
            $identity = UserIdentity::create(['name' => trim($data->name)]);
            $seen = [];
            $canonicalContacts = [];

            foreach ($data->contacts as $contact) {
                $canonical = $this->canonicalizer->canonicalize($contact->type, $contact->value);
                $key = $contact->type->value.'|'.$canonical;

                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('Duplicate contacts are not allowed.');
                }

                $seen[$key] = true;
                $canonicalContacts[] = [$contact, $canonical];
            }

            $explicitPrimaryTypes = [];
            foreach ($canonicalContacts as [$contact]) {
                if ($contact->isPrimary) {
                    $explicitPrimaryTypes[$contact->type->value] = true;
                }
            }

            $createdByType = [];

            foreach ($canonicalContacts as [$contact, $canonical]) {
                $isPrimary = $contact->isPrimary
                    || (! isset($explicitPrimaryTypes[$contact->type->value])
                        && ! isset($createdByType[$contact->type->value]));
                $createdByType[$contact->type->value] = true;

                try {
                    $identity->contacts()->create([
                        'type' => $contact->type,
                        'canonical_value' => $canonical,
                        'is_primary' => $isPrimary,
                    ]);
                } catch (Throwable $exception) {
                    throw new InvalidArgumentException('The contact is already in use.', previous: $exception);
                }
            }

            return $identity->load('contacts');
        });
    }
}
