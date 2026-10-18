<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * تعاملاتِ مخاطب (پچ 096): تعامل می‌تواند مستقیماً برایِ یک مخاطب (بدونِ طرف‌حساب) ثبت شود؛
 * صفحهٔ مخاطب همهٔ تعاملاتِ او (مستقیم و از طرفِ طرف‌حساب‌ها) را نشان می‌دهد.
 */
class CrmPersonInteractionTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

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
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ تعاملِ مخاطب' . ' ' . bin2hex(random_bytes(3)), 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function createPerson(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => 'تعامل‌دار' . ' ' . bin2hex(random_bytes(3))])
            ->assertOk()->json('personId');
    }

    private function typeId(string $code): int
    {
        return (int) DB::selectOne('SELECT InteractionTypeID FROM dbo.CrmInteractionTypes WHERE Code = ?', [$code])->InteractionTypeID;
    }

    private function save(array $body)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/interactions', array_merge([
            'subject' => 'تعاملِ مخاطب', 'interactionDate' => '2026-10-17 11:00:00', 'interactionTypeId' => $this->typeId('CALL'),
        ], $body));
    }

    private function listForPerson(int $personId): array
    {
        return $this->as(self::USER_FULL)->getJson("/crm/interactions?personId={$personId}")->assertOk()->json('items');
    }

    public function test_interaction_can_be_recorded_directly_for_a_person(): void
    {
        $person = $this->createPerson();
        $id = (int) $this->save(['personId' => $person])->assertOk()->assertJson(['success' => true])->json('interactionId');

        $this->assertNull(DB::table('CrmInteractions')->where('InteractionID', $id)->value('PartyID'));
        $items = $this->listForPerson($person);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['PartyID']);
        $this->assertNull($items[0]['PartyName']);
    }

    public function test_party_or_person_is_required(): void
    {
        $res = $this->save([]);
        $res->assertStatus(422);
        $this->assertStringContainsString('طرف‌حساب یا مخاطبِ تعامل باید مشخص باشد', (string) $res->json('message'));

        // همان قاعده در خودِ رویه و جدول
        $sp = DB::selectOne('EXEC dbo.sp_Crm_SaveInteraction @InteractionTypeID = ?, @PartyID = NULL, @PersonID = NULL, @Subject = N\'x\', @InteractionDate = \'2026-10-17 10:00\', @UserID = 2', [$this->typeId('CALL')]);
        $this->assertSame(0, (int) $sp->Success);
    }

    public function test_person_page_lists_direct_and_party_interactions(): void
    {
        $person = $this->createPerson();
        $party = $this->createParty();
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $party, 'personId' => $person])->assertOk();

        $this->save(['personId' => $person, 'subject' => 'مستقیم'])->assertOk();
        $this->save(['partyId' => $party, 'personId' => $person, 'subject' => 'از طرفِ شرکت'])->assertOk();
        $this->save(['partyId' => $party, 'subject' => 'بدونِ این مخاطب'])->assertOk();

        $items = collect($this->listForPerson($person));
        $this->assertEqualsCanonicalizing(['مستقیم', 'از طرفِ شرکت'], $items->pluck('Subject')->all());
        $this->assertStringStartsWith('شرکتِ تعاملِ مخاطب', (string) $items->firstWhere('Subject', 'از طرفِ شرکت')['PartyName']);

        // تعاملِ مستقیمِ مخاطب در فهرستِ طرف‌حساب نمی‌آید
        $partyItems = collect($this->as(self::USER_FULL)->getJson("/crm/interactions?partyId={$party}")->json('items'));
        $this->assertNotContains('مستقیم', $partyItems->pluck('Subject')->all());

        $this->as(self::USER_FULL)->get("/crm/persons/{$person}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Crm/Persons/Show')
            ->has('interactions', 2)
            ->has('interactionTypes')
            ->has('users'));
    }

    public function test_person_interaction_cannot_link_to_a_project(): void
    {
        $person = $this->createPerson();
        $title = 'پروژهٔ تستِ تعاملِ مخاطب ' . strtoupper(bin2hex(random_bytes(4)));
        $this->as(self::USER_FULL)->post('/projects', ['ProjectTitle' => $title, 'ProjectStatusID' => 1, 'ResponsibleUserID' => self::USER_FULL])->assertRedirect();
        $projectId = (int) DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects WHERE ProjectTitle = ? ORDER BY ProjectID DESC', [$title])->ProjectID;

        $res = $this->save(['personId' => $person, 'projectId' => $projectId]);
        $res->assertStatus(422);
        $this->assertStringContainsString('فقط برایِ تعاملِ یک طرف‌حساب', (string) $res->json('message'));
    }

    public function test_owner_of_a_direct_interaction_is_immutable(): void
    {
        $person = $this->createPerson();
        $other = $this->createPerson();
        $id = (int) $this->save(['personId' => $person])->assertOk()->json('interactionId');

        $this->save(['interactionId' => $id, 'personId' => $other])->assertStatus(422);
        $this->save(['interactionId' => $id, 'partyId' => $this->createParty(), 'personId' => $person])->assertStatus(422);

        $this->save(['interactionId' => $id, 'personId' => $person, 'subject' => 'ویرایش‌شده'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame('ویرایش‌شده', DB::table('CrmInteractions')->where('InteractionID', $id)->value('Subject'));
    }

    public function test_followup_of_a_direct_interaction_stays_with_the_same_person(): void
    {
        $person = $this->createPerson();
        $origin = (int) $this->save(['personId' => $person])->assertOk()->json('interactionId');

        $this->save([
            'personId' => $person, 'interactionTypeId' => $this->typeId('FOLLOWUP'), 'followUpOfId' => $origin, 'subject' => 'پیگیری',
        ])->assertOk()->assertJson(['success' => true]);

        $this->save([
            'personId' => $this->createPerson(), 'interactionTypeId' => $this->typeId('FOLLOWUP'), 'followUpOfId' => $origin,
        ])->assertStatus(422);
    }

    public function test_direct_interaction_attachments_are_listed_for_the_person(): void
    {
        $person = $this->createPerson();
        $id = (int) $this->save(['personId' => $person])->assertOk()->json('interactionId');

        $file = \Illuminate\Http\UploadedFile::fake()->create('گزارش.pdf', 10, 'application/pdf');
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->as(self::USER_FULL)->post("/crm/interactions/{$id}/attachments", ['attachments' => [$file], 'descriptions' => ['']])
            ->assertOk()->assertJson(['success' => true]);

        $items = $this->listForPerson($person);
        $this->assertCount(1, $items[0]['Attachments']);
    }
}
