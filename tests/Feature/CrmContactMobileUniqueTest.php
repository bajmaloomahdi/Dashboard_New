<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * شمارهٔ موبایل یکتا (پچ 090): هیچ دو اطلاعاتِ تماسِ «موبایل»ِ فعال — برایِ هر طرف‌حساب یا مخاطبی —
 * نمی‌توانند یک شماره داشته باشند. مقایسه پس از یکسان‌سازی است تا ردیف‌هایِ قدیمی (ارقامِ فارسی، فاصله/خط‌تیره،
 * +98/0098) هم دیده شوند؛ ورودیِ جدید از پچ 092 فقط رقم می‌پذیرد.
 */
class CrmContactMobileUniqueTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function mobileTypeId(): int
    {
        return (int) DB::table('CrmContactTypes')->whereRaw('LTRIM(RTRIM(DisplayName)) = ?', ['موبایل'])->value('ContactTypeID');
    }

    private function phoneTypeId(): int
    {
        return (int) DB::table('CrmContactTypes')->whereRaw('LTRIM(RTRIM(DisplayName)) = ?', ['تلفن'])->value('ContactTypeID');
    }

    /** شماره‌ای که در دادهٔ فعلی (هیچ شکلی از آن) ثبت نشده است — به‌صورتِ 0912xxxxxxx */
    private function freshMobile(): string
    {
        do {
            $n = '0912' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
            $exists = DB::selectOne('SELECT COUNT(*) AS c FROM dbo.CrmContacts WHERE dbo.fn_Crm_NormalizeMobile(ContactValue) = ?', [$n])->c;
        } while ((int) $exists > 0);

        return $n;
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ موبایل ' . $digits, 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function person(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'firstName' => 'نگار', 'lastName' => 'یکتا'])
            ->assertOk()->json('personId');
    }

    private function saveContact(array $payload)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/contacts', $payload);
    }

    public function test_mobile_number_cannot_be_registered_twice_across_parties(): void
    {
        $mobile = $this->freshMobile();
        $partyA = $this->party();
        $partyB = $this->party();

        $this->saveContact(['partyId' => $partyA, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertOk()->assertJson(['success' => true]);

        $res = $this->saveContact(['partyId' => $partyB, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile]);
        $res->assertJson(['success' => false]);
        $this->assertStringContainsString('شمارهٔ موبایلِ تکراری مجاز نیست', (string) $res->json('message'));
        $this->assertStringContainsString('شرکتِ موبایل', (string) $res->json('message'));
    }

    public function test_same_mobile_on_the_same_party_is_also_rejected(): void
    {
        $mobile = $this->freshMobile();
        $party = $this->party();

        $this->saveContact(['partyId' => $party, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])->assertOk();
        $this->saveContact(['partyId' => $party, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertJson(['success' => false]);
    }

    public function test_older_formatted_numbers_still_block_the_same_mobile(): void
    {
        // ورودیِ جدید فقط رقم می‌پذیرد (پچ 092)، ولی ردیف‌هایِ قدیمی‌ای که با فاصله/+98/ارقامِ فارسی ثبت شده‌اند
        // پس از یکسان‌سازی همان شماره حساب می‌شوند.
        $mobileType = $this->mobileTypeId();
        $local = substr($this->freshMobile(), 1);    // 912xxxxxxx
        $mobile = '0' . $local;
        $persian = strtr('+98 ' . $local, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        DB::table('CrmContacts')->insert([
            'PartyID' => $this->party(), 'ContactTypeID' => $mobileType, 'ContactValue' => $persian,
            'IsPrimary' => 0, 'IsActive' => 1, 'UserID_InsertFirst' => self::USER_FULL,
        ]);

        $res = $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $mobileType, 'contactValue' => $mobile]);
        $res->assertJson(['success' => false]);
        $this->assertStringContainsString('شمارهٔ موبایلِ تکراری مجاز نیست', (string) $res->json('message'));
    }

    public function test_uniqueness_spans_parties_and_persons(): void
    {
        $mobile = $this->freshMobile();
        $this->saveContact(['personId' => $this->person(), 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])->assertOk();

        $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertJson(['success' => false]);
    }

    public function test_editing_a_contact_keeping_its_own_number_is_allowed(): void
    {
        $mobile = $this->freshMobile();
        $party = $this->party();
        $id = (int) $this->saveContact(['partyId' => $party, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertOk()->json('contactId');

        $this->saveContact([
            'contactId' => $id, 'partyId' => $party, 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile, 'description' => 'شخصی',
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_rule_applies_only_to_mobile_type(): void
    {
        $number = $this->freshMobile();
        $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $this->phoneTypeId(), 'contactValue' => $number])->assertOk();
        $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $this->phoneTypeId(), 'contactValue' => $number])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_inactive_mobile_does_not_block_and_cannot_be_reactivated_while_duplicate_is_active(): void
    {
        $mobile = $this->freshMobile();
        $first = (int) $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertOk()->json('contactId');

        // غیرفعال‌شده مانع نیست
        $this->as(self::USER_FULL)->postJson("/crm/contacts/{$first}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->saveContact(['partyId' => $this->party(), 'contactTypeId' => $this->mobileTypeId(), 'contactValue' => $mobile])
            ->assertOk()->assertJson(['success' => true]);

        // فعال‌سازیِ دوبارهٔ قبلی در حالی که شمارهٔ فعالِ دیگری هست → رد
        $res = $this->as(self::USER_FULL)->postJson("/crm/contacts/{$first}/toggle");
        $res->assertJson(['success' => false]);
        $this->assertSame(0, (int) DB::table('CrmContacts')->where('ContactID', $first)->value('IsActive'));
    }
}
