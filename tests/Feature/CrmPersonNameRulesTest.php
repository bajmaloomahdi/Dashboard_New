<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * تعریفِ مخاطب (پچ 093): نام اختیاری؛ نامِ خانوادگی و عنوان الزامی.
 * مخاطبِ بدونِ نام در فهرست‌ها بدونِ فاصلهٔ اضافه نمایش داده می‌شود.
 */
class CrmPersonNameRulesTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function savePerson(array $payload)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/persons', $payload);
    }

    public function test_person_without_first_name_is_accepted(): void
    {
        $last = 'بی‌نام ' . bin2hex(random_bytes(3));
        $id = (int) $this->savePerson(['titleId' => $this->crmTitleId(), 'lastName' => $last])
            ->assertOk()->assertJson(['success' => true])->json('personId');

        $this->assertSame('', DB::table('CrmPersons')->where('PersonID', $id)->value('FirstName'));

        $found = collect($this->as(self::USER_FULL)->getJson('/crm/persons?search=' . urlencode($last))->json('items'))->firstWhere('PersonID', $id);
        $this->assertSame($last, $found['DisplayName']);
    }

    public function test_empty_first_name_string_is_accepted(): void
    {
        $this->savePerson(['titleId' => $this->crmTitleId(), 'firstName' => '   ', 'lastName' => 'رحیمی'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_last_name_is_required(): void
    {
        $res = $this->savePerson(['titleId' => $this->crmTitleId(), 'firstName' => 'علی']);
        $res->assertStatus(422);
        $this->assertStringContainsString('نامِ خانوادگی الزامی است', (string) $res->json('message'));

        $this->savePerson(['titleId' => $this->crmTitleId(), 'firstName' => 'علی', 'lastName' => '   '])
            ->assertStatus(422);
    }

    public function test_title_is_required(): void
    {
        $res = $this->savePerson(['firstName' => 'علی', 'lastName' => 'رضایی']);
        $res->assertStatus(422);
        $this->assertStringContainsString('عنوان الزامی است', (string) $res->json('message'));

        $this->savePerson(['titleId' => 999999999, 'lastName' => 'رضایی'])
            ->assertStatus(422);
    }

    public function test_editing_also_requires_title_and_last_name(): void
    {
        $id = (int) $this->savePerson(['titleId' => $this->crmTitleId(), 'firstName' => 'مینا', 'lastName' => 'کاظمی'])->assertOk()->json('personId');

        $this->savePerson(['personId' => $id, 'firstName' => 'مینا', 'lastName' => 'کاظمی'])->assertStatus(422);
        $this->savePerson(['personId' => $id, 'titleId' => $this->crmTitleId(), 'firstName' => 'مینا'])->assertStatus(422);

        // پاک‌کردنِ نام در ویرایش مجاز است
        $this->savePerson(['personId' => $id, 'titleId' => $this->crmTitleId(), 'lastName' => 'کاظمی'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame('', DB::table('CrmPersons')->where('PersonID', $id)->value('FirstName'));
    }

    public function test_stored_procedure_enforces_the_same_rules(): void
    {
        $noTitle = DB::selectOne('EXEC dbo.sp_Crm_SavePerson @FirstName = NULL, @LastName = ?, @TitleID = NULL, @UserID = 2', ['رضایی']);
        $this->assertSame(0, (int) $noTitle->Success);

        $noLast = DB::selectOne('EXEC dbo.sp_Crm_SavePerson @FirstName = ?, @LastName = ?, @TitleID = ?, @UserID = 2', ['علی', ' ', $this->crmTitleId()]);
        $this->assertSame(0, (int) $noLast->Success);

        $ok = DB::selectOne('EXEC dbo.sp_Crm_SavePerson @FirstName = NULL, @LastName = ?, @TitleID = ?, @UserID = 2', ['رضایی', $this->crmTitleId()]);
        $this->assertSame(1, (int) $ok->Success);
    }
}
