<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * نوعِ کاربر (پچِ ۱۲۱): داخلی / مخاطب / طرف‌حساب و اتصال از دو طرف — فرمِ کاربر (sp_SetUserCrmLink) و صفحهٔ CRM
 * (sp_Crm_SetEntityUser). هر مخاطب/طرف‌حساب حداکثر یک کاربر؛ خطایِ اتصال در فرمِ کاربر یعنی کاربر هم ساخته نشود.
 */
class UserCrmLinkTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const ADMIN = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::ADMIN)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'طرف‌حسابِ کاربردار ' . bin2hex(random_bytes(3)), 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function person(): int
    {
        return (int) $this->as(self::ADMIN)->postJson('/crm/persons', [
            'titleId' => $this->crmTitleId(), 'firstName' => 'مخاطبِ', 'lastName' => 'کاربردار ' . bin2hex(random_bytes(3)),
        ])->assertOk()->json('personId');
    }

    private function newUser(array $extra = []): ?object
    {
        $name = 'crmlink_' . bin2hex(random_bytes(4));
        $this->as(self::ADMIN)->post('/users', $extra + ['UserName' => $name, 'Password' => 'secret123', 'FirstName' => 'تست', 'LastName' => 'اتصال']);

        return DB::table('Users')->where('UserName', $name)->first(['UserID', 'UserType', 'CrmPersonID', 'CrmPartyID']);
    }

    private function userRow(int $id): object
    {
        return DB::table('Users')->where('UserID', $id)->first(['UserType', 'CrmPersonID', 'CrmPartyID']);
    }

    public function test_user_form_sets_type_and_link_and_rejects_a_taken_record(): void
    {
        $personId = $this->person();

        $plain = $this->newUser();
        $this->assertSame('INTERNAL', $plain->UserType, 'پیش‌فرض: کاربرِ داخلی');

        $u = $this->newUser(['UserType' => 'PERSON', 'CrmPersonID' => $personId]);
        $this->assertSame('PERSON', $u->UserType);
        $this->assertSame($personId, (int) $u->CrmPersonID);

        // همان مخاطب برایِ کاربرِ دوم → خطا و کاربرِ دوم هم ساخته نمی‌شود
        $name = 'crmlink_dup_' . bin2hex(random_bytes(3));
        $this->as(self::ADMIN)->post('/users', ['UserName' => $name, 'Password' => 'secret123', 'UserType' => 'PERSON', 'CrmPersonID' => $personId])
            ->assertSessionHasErrors('UserType');
        $this->assertFalse(DB::table('Users')->where('UserName', $name)->exists());

        // طرف‌حساب بدونِ انتخابِ رکورد → خطا
        $this->as(self::ADMIN)->put("/users/{$u->UserID}", ['UserType' => 'PARTY'])->assertSessionHasErrors('UserType');
        $this->assertSame('PERSON', $this->userRow($u->UserID)->UserType);

        // کاربرِ داخلی هم می‌تواند مخاطب (برایِ آدرس/تلفن) داشته باشد — پچِ ۱۲۲
        $this->as(self::ADMIN)->put("/users/{$u->UserID}", ['UserType' => 'INTERNAL', 'CrmPersonID' => $personId])->assertSessionHasNoErrors();
        $row = $this->userRow($u->UserID);
        $this->assertSame('INTERNAL', $row->UserType);
        $this->assertSame($personId, (int) $row->CrmPersonID);
        $listed = collect($this->as(self::ADMIN)->get('/users')->assertOk()->viewData('page')['props']['users'])->firstWhere('UserID', $u->UserID);
        $this->assertNotEmpty($listed->CrmLinkName ?? ($listed['CrmLinkName'] ?? null), 'نامِ مخاطبِ کاربرِ داخلی در فهرست');

        // داخلی بدونِ مخاطب
        $this->as(self::ADMIN)->put("/users/{$u->UserID}", ['UserType' => 'INTERNAL'])->assertSessionHasNoErrors();
        $this->assertNull($this->userRow($u->UserID)->CrmPersonID);

        // طرف‌حساب → مخاطب پاک و طرف‌حساب ثبت می‌شود
        $partyId = $this->party();
        $this->as(self::ADMIN)->put("/users/{$u->UserID}", ['UserType' => 'PARTY', 'CrmPartyID' => $partyId, 'CrmPersonID' => $personId])->assertSessionHasNoErrors();
        $row = $this->userRow($u->UserID);
        $this->assertSame('PARTY', $row->UserType);
        $this->assertNull($row->CrmPersonID);
        $this->assertSame($partyId, (int) $row->CrmPartyID);

        // فهرستِ گزینه‌هایِ فرم: کاربرِ متصل کنارِ هر مورد
        $this->as(self::ADMIN)->put("/users/{$u->UserID}", ['UserType' => 'PERSON', 'CrmPersonID' => $personId])->assertSessionHasNoErrors();
        $item = collect($this->as(self::ADMIN)->getJson('/users/crm-options?type=PERSON&search=' . urlencode('کاربردار'))->assertOk()->json('items'))->firstWhere('id', $personId);
        $this->assertSame((int) $u->UserID, $item['linkedUserId']);
    }

    public function test_crm_page_assigns_replaces_and_removes_the_user(): void
    {
        $partyId = $this->party();
        $first = $this->newUser();
        $second = $this->newUser();

        $this->as(self::ADMIN)->postJson("/crm/parties/{$partyId}/user", ['userId' => $first->UserID])->assertOk()->assertJson(['success' => true]);
        $this->assertSame('PARTY', $this->userRow($first->UserID)->UserType);
        $detail = DB::selectOne('EXEC dbo.sp_Crm_GetParty @PartyID = ?', [$partyId]);
        $this->assertSame((int) $first->UserID, (int) $detail->LinkedUserID);

        // جایگزینی: کاربرِ قبلی «داخلی» می‌شود
        $this->as(self::ADMIN)->postJson("/crm/parties/{$partyId}/user", ['userId' => $second->UserID])->assertOk();
        $this->assertSame('INTERNAL', $this->userRow($first->UserID)->UserType);
        $this->assertSame($partyId, (int) $this->userRow($second->UserID)->CrmPartyID);

        // کاربری که به مخاطبِ دیگری وصل است، قابلِ اتصال نیست
        $personId = $this->person();
        $this->as(self::ADMIN)->postJson("/crm/persons/{$personId}/user", ['userId' => $second->UserID])->assertStatus(422);
        $this->assertSame('PARTY', $this->userRow($second->UserID)->UserType);

        // برداشتنِ اتصال
        $this->as(self::ADMIN)->postJson("/crm/parties/{$partyId}/user", ['userId' => null])->assertOk();
        $this->assertSame('INTERNAL', $this->userRow($second->UserID)->UserType);

        $this->as(self::ADMIN)->postJson("/crm/persons/{$personId}/user", ['userId' => $second->UserID])->assertOk();
        $this->assertSame($personId, (int) $this->userRow($second->UserID)->CrmPersonID);

        // از صفحهٔ مخاطب: کاربرِ داخلی با همین مخاطب (پچِ ۱۲۲)
        $this->as(self::ADMIN)->postJson("/crm/persons/{$personId}/user", ['userId' => $first->UserID, 'userType' => 'INTERNAL'])->assertOk();
        $row = $this->userRow($first->UserID);
        $this->assertSame('INTERNAL', $row->UserType);
        $this->assertSame($personId, (int) $row->CrmPersonID);
        $this->assertSame('INTERNAL', $this->userRow($second->UserID)->UserType, 'کاربرِ قبلیِ این مخاطب جدا می‌شود');
        $this->assertNull($this->userRow($second->UserID)->CrmPersonID);
        $this->assertSame('INTERNAL', DB::selectOne('EXEC dbo.sp_Crm_GetPerson @PersonID = ?', [$personId])->LinkedUserType);
        $this->as(self::ADMIN)->postJson("/crm/persons/{$personId}/user", ['userId' => $first->UserID, 'userType' => 'PARTY'])->assertStatus(422);
        $second = $first; // برایِ بررسیِ فهرستِ پایین: کاربرِ متصلِ فعلی

        $users = collect($this->as(self::ADMIN)->getJson('/crm/linkable-users')->assertOk()->json('users'));
        $listed = $users->firstWhere('UserID', (int) $second->UserID);
        $this->assertSame('INTERNAL', $listed['UserType']);
        $this->assertNotNull($listed['CrmLinkName']);
    }
}
