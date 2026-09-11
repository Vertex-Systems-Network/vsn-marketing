<?php

declare(strict_types=1);

namespace Tests\Integration\Contacts;

use App\Modules\Contacts\Application\Actions\AddContactToList;
use App\Modules\Contacts\Application\Actions\CreateContact;
use App\Modules\Contacts\Application\Actions\CreateContactList;
use App\Modules\Contacts\Application\Actions\CreateTag;
use App\Modules\Contacts\Application\Actions\AssignTagToContact;
use App\Modules\Contacts\Application\Actions\RemoveContactFromList;
use App\Modules\Contacts\Application\Actions\RemoveTagFromContact;
use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Contacts\Domain\Lists\ContactList;
use App\Modules\Contacts\Domain\Tag\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListsAndTagsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private CreateContact $createContact;
    private CreateContactList $createContactList;
    private CreateTag $createTag;
    private AddContactToList $addContactToList;
    private RemoveContactFromList $removeContactFromList;
    private AssignTagToContact $assignTagToContact;
    private RemoveTagFromContact $removeTagFromContact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createContact = new CreateContact();
        $this->createContactList = new CreateContactList();
        $this->createTag = new CreateTag();
        $this->addContactToList = new AddContactToList();
        $this->removeContactFromList = new RemoveContactFromList();
        $this->assignTagToContact = new AssignTagToContact();
        $this->removeTagFromContact = new RemoveTagFromContact();
    }

    public function test_create_list_is_workspace_scoped(): void
    {
        $list = $this->createContact->execute(1, 'john@example.com', 'John');
        $list2 = $this->createContactList->execute(1, 'Newsletter Subscribers', 'Main newsletter list');

        $this->assertEquals(1, $list2->workspace_id);
        $this->assertEquals('Newsletter Subscribers', $list2->name);
    }

    public function test_create_tag_is_workspace_scoped(): void
    {
        $tag = $this->createTag->execute(1, 'VIP', '#FFD700');

        $this->assertEquals(1, $tag->workspace_id);
        $this->assertEquals('VIP', $tag->name);
        $this->assertEquals('#FFD700', $tag->color);
    }

    public function test_add_contact_to_list_is_idempotent(): void
    {
        $contact = $this->createContact->execute(1, 'jane@example.com', 'Jane');
        $list = $this->createContactList->execute(1, 'Test List');

        $first = $this->addContactToList->execute($list, $contact);
        $second = $this->addContactToList->execute($list, $contact);

        $this->assertTrue($first);
        $this->assertFalse($second); // Already attached

        $this->assertEquals(1, $list->contacts()->count());
    }

    public function test_remove_contact_from_list(): void
    {
        $contact = $this->createContact->execute(1, 'remove@example.com', 'Remove');
        $list = $this->createContactList->execute(1, 'Remove Test List');

        $this->addContactToList->execute($list, $contact);
        $this->assertEquals(1, $list->contacts()->count());

        $removed = $this->removeContactFromList->execute($list, $contact);
        $this->assertTrue($removed);
        $this->assertEquals(0, $list->contacts()->count());
    }

    public function test_assign_tag_to_contact_is_idempotent(): void
    {
        $contact = $this->createContact->execute(1, 'tagged@example.com', 'Tagged');
        $tag = $this->createTag->execute(1, 'Important');

        $first = $this->assignTagToContact->execute($tag, $contact);
        $second = $this->assignTagToContact->execute($tag, $contact);

        $this->assertTrue($first);
        $this->assertFalse($second); // Already assigned

        $this->assertEquals(1, $tag->contacts()->count());
    }

    public function test_remove_tag_from_contact(): void
    {
        $contact = $this->createContact->execute(1, 'untag@example.com', 'Untag');
        $tag = $this->createTag->execute(1, 'Temporary');

        $this->assignTagToContact->execute($tag, $contact);
        $this->assertEquals(1, $tag->contacts()->count());

        $removed = $this->removeTagFromContact->execute($tag, $contact);
        $this->assertTrue($removed);
        $this->assertEquals(0, $tag->contacts()->count());
    }

    public function test_cross_workspace_list_assignment_fails(): void
    {
        $contactW1 = $this->createContact->execute(1, 'w1@example.com', 'W1 Contact');
        $listW2 = $this->createContactList->execute(2, 'W2 List');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Contact and List must belong to the same workspace');

        $this->addContactToList->execute($listW2, $contactW1);
    }

    public function test_cross_workspace_tag_assignment_fails(): void
    {
        $contactW1 = $this->createContact->execute(1, 'w1tag@example.com', 'W1 Tag Contact');
        $tagW2 = $this->createTag->execute(2, 'W2 Tag');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Contact and Tag must belong to the same workspace');

        $this->assignTagToContact->execute($tagW2, $contactW1);
    }

    public function test_list_uniqueness_within_workspace(): void
    {
        $this->createContactList->execute(1, 'Unique List');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->createContactList->execute(1, 'Unique List'); // Duplicate name in same workspace
    }

    public function test_tag_uniqueness_within_workspace(): void
    {
        $this->createTag->execute(1, 'Unique Tag');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->createTag->execute(1, 'Unique Tag'); // Duplicate name in same workspace
    }
}
