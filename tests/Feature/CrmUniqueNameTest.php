<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * نامِ تکراری (پچِ ۱۰۱ → ۱۱۴): نامِ طرف‌حساب و «نام + نامِ خانوادگی»ِ مخاطب بینِ همهٔ رکوردها (فعال و غیرفعال) پس از یکسان‌سازی
 * (ي/ی، ك/ک، ارقام، فاصله و نیم‌فاصله، حروفِ بزرگ/کوچک) مقایسه می‌شود. بدونِ تأییدِ کاربر هیچ چیزی ثبت نمی‌شود و هشدار (409) با
 * موارد مشابه برمی‌گردد؛ با allowDuplicateName ثبت می‌شود. شناسهٔ ملیِ تکراری همچنان قطعاً رد می‌شود.
 */
class CrmUniqueNameTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function digits(): string
    {
        $d = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $d .= random_int(0, 9);
        }

        return $d;
    }

    private function saveParty(string $name, array $extra = [])
    {
        return $this->as(self::USER_FULL)->postJson('/crm/parties', array_merge([
            'partyNature' => 'LEGAL', 'officialName' => $name, 'identifierNumber' => $this->digits(),
        ], $extra));
    }

    private function savePerson(?string $first, string $last, array $extra = [])
    {
        return $this->as(self::USER_FULL)->postJson('/crm/persons', array_merge(array_filter([
            'titleId' => $this->crmTitleId(), 'firstName' => $first, 'lastName' => $last,
        ], fn ($v) => $v !== null), $extra));
    }

    private function assertDuplicateWarning($res, string $expectedName): void
    {
        $res->assertStatus(409)->assertJson(['success' => false, 'duplicateName' => true]);
        $this->assertStringContainsString('آیا با همین نام ثبت شود', (string) $res->json('message'));
        $this->assertContains($expectedName, array_column($res->json('duplicates'), 'name'));
        $this->assertGreaterThanOrEqual(1, $res->json('duplicateCount'));
    }

    private function partiesNamed(string $marker): int
    {
        return DB::table('CrmParties')->where('OfficialName', 'like', "%{$marker}%")->count();
    }

    public function test_duplicate_party_name_warns_and_saves_nothing_without_confirmation(): void
    {
        $marker = (string) random_int(100000, 999999);
        $base = "شرکت یکتای آزمون {$marker}";
        $id = (int) $this->saveParty($base)->assertOk()->json('partyId');

        foreach ([$base, '  ' . $base . ' ', str_replace(['ی', 'ک'], ['ي', 'ك'], $base), str_replace(' ', '‌', $base)] as $variant) {
            $this->assertDuplicateWarning($this->saveParty($variant), $base);
        }
        $this->assertSame(1, $this->partiesNamed($marker), 'بدونِ تأیید هیچ طرف‌حسابی ثبت نشد');

        // ویرایشِ همان طرف‌حساب با نامِ خودش بدونِ هشدار
        $this->saveParty($base, ['partyId' => $id])->assertOk()->assertJson(['success' => true]);
    }

    public function test_confirmed_duplicate_party_is_saved_and_later_edits_do_not_warn_again(): void
    {
        $marker = (string) random_int(100000, 999999);
        $name = "شرکت تأییدی {$marker}";
        $this->saveParty($name)->assertOk();

        $res = $this->saveParty($name, ['allowDuplicateName' => true])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, $this->partiesNamed($marker));
        // ویرایشِ رکوردِ تأییدشده بدونِ تغییرِ نام → بدونِ هشدارِ دوباره
        $this->saveParty($name, ['partyId' => (int) $res->json('partyId'), 'description' => 'ویرایش'])->assertOk();
        // سومی بدونِ تأیید → هشدار با هر دو موردِ مشابه
        $this->assertSame(2, $this->saveParty($name)->assertStatus(409)->json('duplicateCount'));
    }

    public function test_inactive_party_name_also_warns(): void
    {
        $name = 'شرکت غیرفعال آزمون ' . random_int(100000, 999999);
        $id = (int) $this->saveParty($name)->assertOk()->json('partyId');
        $this->as(self::USER_FULL)->postJson("/crm/parties/{$id}/toggle")->assertOk()->assertJson(['success' => true]);

        $res = $this->saveParty($name);
        $res->assertStatus(409);
        $this->assertStringContainsString('(غیرفعال)', (string) $res->json('message'));
        $this->assertFalse((bool) $res->json('duplicates.0.isActive'));
    }

    public function test_renaming_a_party_to_an_existing_name_needs_confirmation(): void
    {
        $a = 'شرکت الف ' . random_int(100000, 999999);
        $this->saveParty($a)->assertOk();
        $b = 'شرکت ب ' . random_int(100000, 999999);
        $bId = (int) $this->saveParty($b)->assertOk()->json('partyId');

        $this->assertDuplicateWarning($this->saveParty($a, ['partyId' => $bId]), $a);
        $this->assertSame($b, DB::table('CrmParties')->where('PartyID', $bId)->value('OfficialName'), 'بدونِ تأیید نام عوض نشد');
        $this->saveParty($a, ['partyId' => $bId, 'allowDuplicateName' => true])->assertOk();
        $this->assertSame($a, DB::table('CrmParties')->where('PartyID', $bId)->value('OfficialName'));
    }

    public function test_duplicate_identifier_is_still_rejected_even_with_confirmation(): void
    {
        $ident = $this->digits();
        $this->saveParty('شرکت شناسه ' . random_int(100000, 999999), ['identifierNumber' => $ident])->assertOk();

        $res = $this->saveParty('شرکت شناسه دیگر ' . random_int(100000, 999999), ['identifierNumber' => $ident, 'allowDuplicateName' => true]);
        $res->assertStatus(422)->assertJsonMissing(['duplicateName' => true]);
        $this->assertStringContainsString('شناسه', (string) $res->json('message'));
    }

    public function test_duplicate_person_name_warns_regardless_of_title_and_saves_after_confirmation(): void
    {
        $last = 'یکتایی' . random_int(100000, 999999);
        $id = (int) $this->savePerson('مریم', $last)->assertOk()->json('personId');

        // عنوانِ دیگر، همان نام → هشدار، ثبت نشد
        $otherTitle = (int) $this->as(self::USER_FULL)->postJson('/crm/directory/titles', ['displayName' => 'عنوانِ دیگر ' . random_int(1000, 9999)])
            ->assertOk()->json('titleId');
        $res = $this->savePerson('مريم', $last, ['titleId' => $otherTitle]);
        $this->assertDuplicateWarning($res, "مریم {$last}");
        $this->assertStringContainsString('فرد مرتبطی با نامِ', (string) $res->json('message'));
        $this->assertSame(1, DB::table('CrmPersons')->where('LastName', $last)->count());

        // با تأیید ثبت می‌شود
        $this->savePerson('مريم', $last, ['titleId' => $otherTitle, 'allowDuplicateName' => true])->assertOk();
        $this->assertSame(2, DB::table('CrmPersons')->where('LastName', $last)->count());

        // همان نامِ خانوادگی با نامِ دیگر یا بدونِ نام → بدونِ هشدار
        $this->savePerson('زهرا', $last)->assertOk()->assertJson(['success' => true]);
        $this->savePerson(null, $last)->assertOk()->assertJson(['success' => true]);
        // ... ولی دومین «بدونِ نام» با همان نامِ خانوادگی هشدار می‌دهد
        $this->savePerson(null, $last)->assertStatus(409);

        // ویرایشِ همان مخاطب با نامِ خودش بدونِ هشدار
        $this->savePerson('مریم', $last, ['personId' => $id])->assertOk()->assertJson(['success' => true]);
    }
}
