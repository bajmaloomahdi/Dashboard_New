<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * نامِ تکراری مجاز نیست (پچ 101): نامِ طرف‌حساب و «نام + نامِ خانوادگی»ِ مخاطب بینِ همهٔ رکوردها (فعال و غیرفعال)
 * یکتاست؛ مقایسه پس از یکسان‌سازی (ي/ی، ك/ک، ارقام، فاصله و نیم‌فاصله، حروفِ بزرگ/کوچک) انجام می‌شود.
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

    public function test_duplicate_party_name_is_rejected_including_formatting_variants(): void
    {
        $base = 'شرکت یکتای آزمون ' . random_int(1000, 9999);
        $id = (int) $this->saveParty($base)->assertOk()->json('partyId');

        foreach ([$base, '  ' . $base . ' ', str_replace(['ی', 'ک'], ['ي', 'ك'], $base), str_replace(' ', '‌', $base)] as $variant) {
            $res = $this->saveParty($variant);
            $res->assertStatus(422);
            $this->assertStringContainsString('نامِ تکراری مجاز نیست', (string) $res->json('message'));
        }

        // ویرایشِ همان طرف‌حساب با نامِ خودش مجاز است
        $this->saveParty($base, ['partyId' => $id])->assertOk()->assertJson(['success' => true]);
    }

    public function test_inactive_party_name_is_also_reserved(): void
    {
        $name = 'شرکت غیرفعال آزمون ' . random_int(1000, 9999);
        $id = (int) $this->saveParty($name)->assertOk()->json('partyId');
        $this->as(self::USER_FULL)->postJson("/crm/parties/{$id}/toggle")->assertOk()->assertJson(['success' => true]);

        $res = $this->saveParty($name);
        $res->assertStatus(422);
        $this->assertStringContainsString('(غیرفعال)', (string) $res->json('message'));
    }

    public function test_renaming_a_party_to_an_existing_name_is_rejected(): void
    {
        $a = 'شرکت الف ' . random_int(1000, 9999);
        $this->saveParty($a)->assertOk();
        $bId = (int) $this->saveParty('شرکت ب ' . random_int(1000, 9999))->assertOk()->json('partyId');

        $this->saveParty($a, ['partyId' => $bId])->assertStatus(422);
    }

    public function test_duplicate_person_name_is_rejected_regardless_of_title(): void
    {
        $last = 'یکتایی' . random_int(1000, 9999);
        $id = (int) $this->savePerson('مریم', $last)->assertOk()->json('personId');

        // عنوانِ دیگر، همان نام → تکراری
        $otherTitle = (int) $this->as(self::USER_FULL)->postJson('/crm/directory/titles', ['displayName' => 'عنوانِ دیگر ' . random_int(1000, 9999)])
            ->assertOk()->json('titleId');
        $res = $this->savePerson('مريم', $last, ['titleId' => $otherTitle]);
        $res->assertStatus(422);
        $this->assertStringContainsString('مخاطبی با نامِ', (string) $res->json('message'));

        // همان نامِ خانوادگی با نامِ دیگر یا بدونِ نام → مجاز
        $this->savePerson('زهرا', $last)->assertOk()->assertJson(['success' => true]);
        $this->savePerson(null, $last)->assertOk()->assertJson(['success' => true]);
        // ... ولی دومین «بدونِ نام» با همان نامِ خانوادگی تکراری است
        $this->savePerson(null, $last)->assertStatus(422);

        // ویرایشِ همان مخاطب با نامِ خودش مجاز است
        $this->savePerson('مریم', $last, ['personId' => $id])->assertOk()->assertJson(['success' => true]);
    }
}
