<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * پچِ ۱۳۵ — تبِ «پروژه‌ها»ی طرف‌حساب: پروژه‌هایی که طرف‌حساب مالک یا پیمانکارِ فعالشان است؛ فقط پروژه‌هایی که کاربر
 * (سازنده یا عضو) اجازهٔ دیدنشان را دارد نمایش داده می‌شوند و از بقیه فقط تعداد.
 *
 * کاربران: 2 مسئولِ پروژه‌ها، 3 کاربرِ دیگر (عضوِ هیچ‌کدام نیست). طرف‌حسابِ واقعیِ از‌پیش‌موجود: 766.
 */
class CrmPartyProjectsTabTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const RESPONSIBLE = 2;
    private const OUTSIDER = 3;
    private const PARTY = 766;

    private function createProject(string $label): int
    {
        $title = "پروژهٔ تبِ طرف‌حساب {$label} " . strtoupper(bin2hex(random_bytes(4)));
        $this->actingAs(User::find(self::RESPONSIBLE))->post('/projects', [
            'ProjectTitle' => $title, 'ProjectStatusID' => 1, 'ResponsibleUserID' => self::RESPONSIBLE,
        ])->assertRedirect();

        return (int) DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects WHERE ProjectTitle = ? ORDER BY ProjectID DESC', [$title])->ProjectID;
    }

    private function tab(int $userId): array
    {
        $data = null;
        $this->actingAs(User::find($userId))->get('/crm/parties/' . self::PARTY)->assertOk()
            ->assertInertia(function ($page) use (&$data) {
                $page->component('Crm/Parties/Show')->has('partyProjects');
                $data = $page->toArray()['props']['partyProjects'];
            });

        return $data;
    }

    public function test_owner_and_contractor_projects_appear_with_role_and_respect_project_visibility(): void
    {
        $before = $this->tab(self::RESPONSIBLE);
        $owned = $this->createProject('مالک');
        $contracted = $this->createProject('پیمانکار');
        $unrelated = $this->createProject('بی‌ربط');
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$owned}/owner", ['OwnerPartyID' => self::PARTY, 'OwnerBrandID' => null])->assertOk();
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$contracted}/contractors", ['PartyID' => self::PARTY])->assertOk();

        $after = $this->tab(self::RESPONSIBLE);
        $byId = collect($after['items'])->keyBy(fn ($p) => (int) $p['ProjectID']);
        $this->assertTrue($byId->has($owned) && $byId->has($contracted));
        $this->assertFalse($byId->has($unrelated), 'پروژهٔ بی‌ربط نمی‌آید');
        $this->assertSame([1, 0], [(int) $byId[$owned]['IsOwner'], (int) $byId[$owned]['IsContractor']]);
        $this->assertSame([0, 1], [(int) $byId[$contracted]['IsOwner'], (int) $byId[$contracted]['IsContractor']]);
        $this->assertCount(count($before['items']) + 2, $after['items']);

        // کاربرِ دیگر عضوِ این پروژه‌ها نیست → نه عنوان، فقط تعداد
        $outsider = $this->tab(self::OUTSIDER);
        $ids = array_map(fn ($p) => (int) $p['ProjectID'], $outsider['items']);
        $this->assertNotContains($owned, $ids);
        $this->assertNotContains($contracted, $ids);
        $this->assertGreaterThanOrEqual(2, $outsider['hiddenCount']);

        // پیمانکار حذف شود → دیگر نمی‌آید
        $this->actingAs(User::find(self::RESPONSIBLE))->deleteJson("/projects/{$contracted}/contractors", ['PartyID' => self::PARTY])->assertOk();
        $ids = array_map(fn ($p) => (int) $p['ProjectID'], $this->tab(self::RESPONSIBLE)['items']);
        $this->assertNotContains($contracted, $ids, 'پیمانکارِ غیرفعال');
        $this->assertContains($owned, $ids);
    }

    /** پچِ ۱۳۶ — فردِ مرتبط: پروژه‌هایِ طرف‌حساب‌هایش (با نام و نقش) و پروژه‌ای که کاربرِ متصل به او عضوش است */
    public function test_person_tab_shows_projects_through_related_parties_and_linked_user_membership(): void
    {
        $owned = $this->createProject('فرد-مالک');
        $memberOnly = $this->createProject('فرد-عضو');
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$owned}/owner", ['OwnerPartyID' => self::PARTY, 'OwnerBrandID' => null])->assertOk();

        $personId = (int) $this->actingAs(User::find(self::RESPONSIBLE))->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => 'فردِ پروژه ' . bin2hex(random_bytes(3))])
            ->assertOk()->json('personId');
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson('/crm/relations', ['partyId' => self::PARTY, 'personId' => $personId])->assertOk();
        // کاربرِ متصل به این فرد عضوِ پروژهٔ دوم می‌شود
        DB::update('UPDATE dbo.Users SET CrmPersonID = ? WHERE UserID = ?', [$personId, self::OUTSIDER]);
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$memberOnly}/members", ['UserID' => self::OUTSIDER, 'IsResponsible' => false])->assertOk();

        $data = null;
        $this->actingAs(User::find(self::RESPONSIBLE))->get("/crm/persons/{$personId}")->assertOk()
            ->assertInertia(function ($page) use (&$data) {
                $page->component('Crm/Persons/Show')->has('personProjects');
                $data = $page->toArray()['props']['personProjects'];
            });
        $byId = collect($data['items'])->keyBy(fn ($p) => (int) $p['ProjectID']);
        $this->assertTrue($byId->has($owned), 'از راهِ طرف‌حساب');
        $this->assertStringContainsString('(مالک)', (string) $byId[$owned]['ViaParties']);
        $this->assertSame(0, (int) $byId[$owned]['IsMember']);
        $this->assertTrue($byId->has($memberOnly), 'عضویتِ کاربرِ متصل');
        $this->assertSame(1, (int) $byId[$memberOnly]['IsMember']);
        $this->assertSame('', (string) $byId[$memberOnly]['ViaParties']);
    }
}
