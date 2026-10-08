<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\SupersedeUserContactData;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SupersedeUserContactAction
{
    public function execute(UserIdentity $identity, SupersedeUserContactData $data): void
    {
        DB::transaction(function () use ($identity, $data): void {
            $contact = $identity->activeContacts()->whereKey($data->contactId)->first();

            if ($contact === null) {
                throw new InvalidArgumentException('The active contact was not found.');
            }

            if ($identity->activeContacts()->count() <= 1) {
                throw new InvalidArgumentException('An identity requires at least one active contact.');
            }

            if ($contact->is_primary && $data->replacementContactId === null) {
                throw new InvalidArgumentException('A primary contact requires an active replacement.');
            }

            if ($data->replacementContactId !== null) {
                $replacement = $identity->activeContacts()
                    ->whereKey($data->replacementContactId)
                    ->where('type', $contact->getRawOriginal('type'))
                    ->first();

                if ($replacement === null) {
                    throw new InvalidArgumentException('The replacement contact was not found.');
                }

                $replacement->update(['is_primary' => true]);
            }

            $contact->update([
                'is_primary' => false,
                'superseded_at' => now(),
                'superseded_reason' => trim($data->reason),
            ]);
        });
    }
}
