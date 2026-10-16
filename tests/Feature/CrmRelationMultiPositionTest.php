<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سمت‌هایِ چندگانهٔ مخاطب (رابطهٔ طرف‌حساب↔شخص؛ CrmPartyPersonRelationPositions، پچ 082) + نقش‌هایِ چندگانهٔ همان رابطه.
 * ستونِ قدیمیِ CrmPartyPersonRelations.PositionID فقط اولین سمتِ انتخاب‌شده را نگه می‌دارد.
 */
class CrmRelationMultiPositionTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function digits(int $length): string
    {
        $s = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $s .= (string) random_int(0, 9);
        }

        return $s;
    }

    private function position(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/positions', ['displayName' => 'سمتِ مخاطب ' . $this->digits(5)])
            ->assertOk()->json('positionId');
    }

    private function role(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', ['displayName' => 'نقشِ مخاطب ' . $this->digits(5)])
            ->assertOk()->json('contactRoleId');
    }

    /** @return array{0:int,1:int} [partyId, personId] */
    private function partyAndPerson(): array
    {
        $partyId = (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL',
            'officialName' => 'شرکتِ مخاطب‌دار',
            'identifierNumber' => $this->digits(11),
        ])->assertOk()->json('partyId');
        $personId = (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'آزمون', 'lastName' => 'مخاطب ' . $this->digits(4)])
            ->assertOk()->json('personId');

        return [$partyId, $personId];
    }

    private function saveRelation(array $payload): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/relations', $payload)
            ->assertOk()->assertJson(['success' => true])->json('relationId');
    }

    public function test_relation_saves_multiple_positions_and_roles(): void
    {
        [$partyId, $personId] = $this->partyAndPerson();
        [$p1, $p2] = [$this->position(), $this->position()];
        [$r1, $r2] = [$this->role(), $this->role()];

        $relationId = $this->saveRelation(['partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p2, $p1], 'roleIds' => [$r1, $r2]]);

        $ids = $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->assertOk()->json();
        $this->assertEqualsCanonicalizing([$p1, $p2], $ids['positionIds']);
        $this->assertEqualsCanonicalizing([$r1, $r2], $ids['roleIds']);
        $this->assertSame($p2, (int) DB::table('CrmPartyPersonRelations')->where('RelationID', $relationId)->value('PositionID'));

        $row = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$partyId}")->assertOk()->json('items'))->firstWhere('RelationID', $relationId);
        $names = DB::table('CrmPositions')->whereIn('PositionID', [$p1, $p2])->pluck('DisplayName')->all();
        foreach ($names as $n) {
            $this->assertStringContainsString($n, $row['PositionName']);
        }
        $this->assertNotEmpty($row['RoleNames']);
    }

    public function test_edit_replaces_positions_and_empty_list_clears_them(): void
    {
        [$partyId, $personId] = $this->partyAndPerson();
        [$p1, $p2, $p3] = [$this->position(), $this->position(), $this->position()];

        $relationId = $this->saveRelation(['partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p1, $p2]]);
        $this->saveRelation(['relationId' => $relationId, 'partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p3, $p2]]);
        $this->assertEqualsCanonicalizing([$p2, $p3], $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->json('positionIds'));
        $this->assertSame($p3, (int) DB::table('CrmPartyPersonRelations')->where('RelationID', $relationId)->value('PositionID'));

        $this->saveRelation(['relationId' => $relationId, 'partyId' => $partyId, 'personId' => $personId, 'positionIds' => []]);
        $this->assertSame([], $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->json('positionIds'));
        $this->assertNull(DB::table('CrmPartyPersonRelations')->where('RelationID', $relationId)->value('PositionID'));
    }

    public function test_legacy_single_position_id_still_works(): void
    {
        [$partyId, $personId] = $this->partyAndPerson();
        $p1 = $this->position();

        $relationId = $this->saveRelation(['partyId' => $partyId, 'personId' => $personId, 'positionId' => $p1]);
        $this->assertSame([$p1], $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->json('positionIds'));
    }

    public function test_invalid_or_duplicate_position_ids_are_rejected(): void
    {
        [$partyId, $personId] = $this->partyAndPerson();
        $p1 = $this->position();

        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId, 'positionIds' => [999999999]])->assertStatus(422);
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p1, $p1]])->assertStatus(422);
        $this->assertSame(0, DB::table('CrmPartyPersonRelations')->where('PartyID', $partyId)->count());
    }

    public function test_new_inactive_position_is_rejected_but_existing_inactive_is_kept(): void
    {
        [$partyId, $personId] = $this->partyAndPerson();
        [$p1, $p2] = [$this->position(), $this->position()];

        $relationId = $this->saveRelation(['partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p1]]);
        DB::table('CrmPositions')->whereIn('PositionID', [$p1, $p2])->update(['IsActive' => 0]);

        $this->saveRelation(['relationId' => $relationId, 'partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p1]]);
        $this->assertSame([$p1], $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->json('positionIds'));

        $this->as(self::USER_FULL)->postJson('/crm/relations', ['relationId' => $relationId, 'partyId' => $partyId, 'personId' => $personId, 'positionIds' => [$p1, $p2]])
            ->assertJson(['success' => false]);
        $this->assertSame([$p1], $this->as(self::USER_FULL)->getJson("/crm/relations/{$relationId}/roles")->json('positionIds'));
    }
}
