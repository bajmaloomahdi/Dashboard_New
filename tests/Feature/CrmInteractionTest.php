<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CRM — تعاملات (CrmInteractions): تماس/جلسه/یادداشت/پیگیری، با قواعدِ وضعیتِ
 * اعمال‌شده در Backend و اعتبارسنجیِ رابطهٔ مخاطب↔طرف‌حساب.
 *
 * کاربران: 2 → RoleID=1 (شاملِ CRM_VIEW و CRM_MANAGE_PARTIES)، 3 → بدونِ این دو.
 */
class CrmInteractionTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function createParty(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ تعامل', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function createPerson(string $name = 'مخاطب'): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => $name, 'lastName' => 'تست'])->assertOk()->json('personId');
    }

    private function relate(int $partyId, int $personId): void
    {
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId])->assertOk();
    }

    private function save(array $body)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/interactions', array_merge([
            'subject' => 'موضوعِ تست', 'interactionDate' => '2026-09-25 10:30:00',
        ], $body));
    }

    private function list(int $partyId): array
    {
        return $this->as(self::USER_FULL)->getJson("/crm/interactions?partyId={$partyId}")->assertOk()->json('items');
    }

    public function test_permissions(): void
    {
        $party = $this->createParty();
        $this->as(self::USER_NOPERM)->postJson('/crm/interactions', ['partyId' => $party])->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson("/crm/interactions?partyId={$party}")->assertStatus(403);
    }

    public function test_call_and_meeting_default_to_done_and_may_be_planned(): void
    {
        $party = $this->createParty();

        $call = $this->save(['partyId' => $party, 'interactionType' => 'CALL'])->assertOk()->json();
        $meeting = $this->save(['partyId' => $party, 'interactionType' => 'MEETING', 'status' => 'PLANNED'])->assertOk()->json();

        $items = collect($this->list($party));
        $this->assertSame('DONE', $items->firstWhere('InteractionID', $call['interactionId'])['Status']);
        $this->assertSame('PLANNED', $items->firstWhere('InteractionID', $meeting['interactionId'])['Status']);

        $this->save(['partyId' => $party, 'interactionType' => 'CALL', 'status' => 'CANCELED'])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_note_is_always_done(): void
    {
        $party = $this->createParty();

        $note = $this->save(['partyId' => $party, 'interactionType' => 'NOTE'])->assertOk()->json();
        $this->assertSame('DONE', $this->list($party)[0]['Status']);

        $this->save(['partyId' => $party, 'interactionType' => 'NOTE', 'status' => 'PLANNED'])->assertStatus(422);

        $this->as(self::USER_FULL)->postJson("/crm/interactions/{$note['interactionId']}/status", ['status' => 'CANCELED'])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_followup_defaults_to_planned_and_cannot_start_done(): void
    {
        $party = $this->createParty();

        $this->save(['partyId' => $party, 'interactionType' => 'FOLLOWUP'])->assertOk();
        $this->assertSame('PLANNED', $this->list($party)[0]['Status']);

        $this->save(['partyId' => $party, 'interactionType' => 'FOLLOWUP', 'status' => 'DONE'])->assertStatus(422);
    }

    public function test_status_transitions_only_from_planned_and_edit_cannot_bypass_them(): void
    {
        $party = $this->createParty();
        $fu = $this->save(['partyId' => $party, 'interactionType' => 'FOLLOWUP'])->assertOk()->json();
        $id = $fu['interactionId'];

        // ویرایش نمی‌تواند وضعیت را عوض کند
        $this->save(['interactionId' => $id, 'partyId' => $party, 'interactionType' => 'FOLLOWUP', 'status' => 'DONE', 'subject' => 'ویرایش'])->assertOk();
        $this->assertSame('PLANNED', $this->list($party)[0]['Status']);

        $this->as(self::USER_FULL)->postJson("/crm/interactions/{$id}/status", ['status' => 'PLANNED'])->assertStatus(422);
        $this->as(self::USER_FULL)->postJson("/crm/interactions/{$id}/status", ['status' => 'DONE'])->assertOk()->assertJson(['success' => true]);
        $this->as(self::USER_FULL)->postJson("/crm/interactions/{$id}/status", ['status' => 'CANCELED'])->assertStatus(422);
    }

    public function test_type_and_party_are_immutable_after_creation(): void
    {
        $party = $this->createParty();
        $call = $this->save(['partyId' => $party, 'interactionType' => 'CALL'])->assertOk()->json();

        $this->save(['interactionId' => $call['interactionId'], 'partyId' => $party, 'interactionType' => 'NOTE'])->assertStatus(422);
        $this->save(['interactionId' => $call['interactionId'], 'partyId' => $this->createParty(), 'interactionType' => 'CALL'])->assertStatus(422);
    }

    public function test_person_must_be_related_to_the_same_party(): void
    {
        $party = $this->createParty();
        $person = $this->createPerson();

        $this->save(['partyId' => $party, 'interactionType' => 'CALL', 'personId' => $person])->assertStatus(422)->assertJson(['success' => false]);

        $this->relate($party, $person);
        $this->save(['partyId' => $party, 'interactionType' => 'CALL', 'personId' => $person])->assertOk();

        $found = $this->list($party)[0];
        $this->assertSame($person, (int) $found['PersonID']);
        $this->assertNotEmpty($found['PersonName']);
    }

    public function test_person_related_to_another_party_is_rejected(): void
    {
        $partyA = $this->createParty();
        $partyB = $this->createParty();
        $person = $this->createPerson();
        $this->relate($partyA, $person);

        $this->save(['partyId' => $partyB, 'interactionType' => 'MEETING', 'personId' => $person])->assertStatus(422);
    }

    public function test_owner_defaults_to_the_creator(): void
    {
        $party = $this->createParty();
        $this->save(['partyId' => $party, 'interactionType' => 'NOTE'])->assertOk();

        $this->assertSame(self::USER_FULL, (int) $this->list($party)[0]['OwnerUserID']);
    }

    public function test_followup_links_to_origin_in_same_party_only(): void
    {
        $party = $this->createParty();
        $other = $this->createParty();

        $call = $this->save(['partyId' => $party, 'interactionType' => 'CALL'])->assertOk()->json();
        $this->save(['partyId' => $party, 'interactionType' => 'FOLLOWUP', 'followUpOfId' => $call['interactionId']])->assertOk();

        $this->save(['partyId' => $other, 'interactionType' => 'FOLLOWUP', 'followUpOfId' => $call['interactionId']])->assertStatus(422);
        $this->save(['partyId' => $party, 'interactionType' => 'CALL', 'followUpOfId' => $call['interactionId']])->assertStatus(422);
    }

    public function test_list_is_newest_first_and_filterable_by_type(): void
    {
        $party = $this->createParty();
        $this->save(['partyId' => $party, 'interactionType' => 'NOTE', 'subject' => 'قدیمی', 'interactionDate' => '2026-01-01 09:00:00'])->assertOk();
        $this->save(['partyId' => $party, 'interactionType' => 'CALL', 'subject' => 'جدید', 'interactionDate' => '2026-06-01 09:00:00'])->assertOk();

        $items = $this->list($party);
        $this->assertSame('جدید', $items[0]['Subject']);

        $notes = $this->as(self::USER_FULL)->getJson("/crm/interactions?partyId={$party}&type=NOTE")->assertOk()->json('items');
        $this->assertCount(1, $notes);
    }

    public function test_subject_and_date_are_required_and_toggle_works(): void
    {
        $party = $this->createParty();
        $this->as(self::USER_FULL)->postJson('/crm/interactions', ['partyId' => $party, 'interactionType' => 'NOTE', 'interactionDate' => '2026-09-25 10:00:00'])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->as(self::USER_FULL)->postJson('/crm/interactions', ['partyId' => $party, 'interactionType' => 'NOTE', 'subject' => 'x'])
            ->assertStatus(422)->assertJson(['success' => false]);

        $note = $this->save(['partyId' => $party, 'interactionType' => 'NOTE'])->assertOk()->json();
        $this->as(self::USER_FULL)->postJson("/crm/interactions/{$note['interactionId']}/toggle")->assertOk();
        $this->assertFalse((bool) $this->list($party)[0]['IsActive']);
    }

    public function test_party_show_page_receives_interactions(): void
    {
        $party = $this->createParty();
        $this->save(['partyId' => $party, 'interactionType' => 'NOTE'])->assertOk();

        $props = $this->as(self::USER_FULL)->get("/crm/parties/{$party}")->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['interactions']);
        $this->assertNotEmpty($props['users']);
    }
}
