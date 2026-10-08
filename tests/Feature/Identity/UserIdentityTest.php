<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Application\Actions\CreateUserIdentityAction;
use App\Contexts\Identity\Application\Actions\SupersedeUserContactAction;
use App\Contexts\Identity\Application\DTOs\ContactData;
use App\Contexts\Identity\Application\DTOs\CreateUserIdentityData;
use App\Contexts\Identity\Application\DTOs\SupersedeUserContactData;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class UserIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_only_identity_is_created_with_a_uuidv7_public_identifier(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, '  Alice@Example.TEST '),
        ]);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $identity->public_id,
        );
        $contact = $identity->contacts->first();
        self::assertInstanceOf(UserContact::class, $contact);
        self::assertSame('alice@example.test', $contact->canonical_value);
        self::assertTrue($contact->is_primary);
    }

    public function test_phone_only_identity_is_canonicalized_to_e164(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Phone, '+234 803 123 4567'),
        ]);

        self::assertSame('+2348031234567', $identity->contacts->first()->canonical_value);
    }

    public function test_multiple_contacts_are_stored_with_one_primary_per_type(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, 'first@example.test'),
            new ContactData(ContactType::Email, 'second@example.test', true),
            new ContactData(ContactType::Phone, '+14155552671'),
        ]);

        self::assertSame(3, $identity->contacts->count());
        self::assertSame(2, $identity->contacts->where('is_primary', true)->count());
    }

    public function test_duplicate_active_contact_is_rejected_globally(): void
    {
        $this->createIdentity([new ContactData(ContactType::Email, 'shared@example.test')]);

        $this->expectException(InvalidArgumentException::class);
        $this->createIdentity([new ContactData(ContactType::Email, ' SHARED@example.test ')]);
    }

    public function test_invalid_and_local_phone_values_are_rejected(): void
    {
        foreach (['08031234567', '+999123'] as $value) {
            try {
                $this->createIdentity([new ContactData(ContactType::Phone, $value)]);
                self::fail('An invalid phone number was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The phone contact is invalid.', $exception->getMessage());
            }
        }
    }

    public function test_identity_requires_at_least_one_contact(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createIdentity([]);
    }

    public function test_superseded_contact_is_retained_and_can_be_reused(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, 'old@example.test'),
            new ContactData(ContactType::Email, 'replacement@example.test'),
            new ContactData(ContactType::Phone, '+14155552671'),
        ]);
        $oldContact = $identity->contacts->firstWhere('canonical_value', 'old@example.test');

        (new SupersedeUserContactAction)->execute(
            $identity,
            new SupersedeUserContactData(
                $oldContact->id,
                'replaced by user',
                $identity->contacts->firstWhere('canonical_value', 'replacement@example.test')->id,
            ),
        );

        $replacement = $this->createIdentity([
            new ContactData(ContactType::Email, 'old@example.test'),
        ]);

        $this->assertDatabaseHas('user_contacts', [
            'id' => $oldContact->id,
            'superseded_reason' => 'replaced by user',
        ]);
        self::assertSame('old@example.test', $replacement->contacts->first()->canonical_value);
    }

    public function test_last_active_contact_cannot_be_superseded(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, 'only@example.test'),
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new SupersedeUserContactAction)->execute(
            $identity,
            new SupersedeUserContactData($identity->contacts->first()->id, 'not allowed'),
        );
    }

    public function test_primary_contact_requires_an_active_replacement(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, 'primary@example.test'),
            new ContactData(ContactType::Phone, '+14155552671'),
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new SupersedeUserContactAction)->execute(
            $identity,
            new SupersedeUserContactData($identity->contacts->first()->id, 'replacement required'),
        );
    }

    public function test_contact_verification_state_is_not_accepted_by_the_creation_contract(): void
    {
        $identity = $this->createIdentity([
            new ContactData(ContactType::Email, 'unverified@example.test'),
        ]);

        self::assertNull($identity->contacts->first()->verified_at);
    }

    /** @param list<ContactData> $contacts */
    private function createIdentity(array $contacts): UserIdentity
    {
        return app(CreateUserIdentityAction::class)->execute(
            new CreateUserIdentityData('Fictional Identity', $contacts),
        );
    }
}
