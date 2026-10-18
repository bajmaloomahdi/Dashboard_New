<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * شماره‌ها (پچ‌های 091 و 092): فقط رقمِ لاتین، بدونِ هیچ نویسهٔ دیگری (فاصله، خط‌تیره، +98، پرانتز، ارقامِ فارسی).
 * موبایل = ۱۱ رقم با ۰۹؛ تلفن/فکس = ۱۱ رقم با پیش‌شمارهٔ ۰… یا ۸ رقم بدونِ پیش‌شماره؛ بقیهٔ نوع‌ها بدونِ کنترل.
 */
class CrmContactNumberFormatTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function typeId(string $name): int
    {
        return (int) DB::table('CrmContactTypes')->whereRaw('LTRIM(RTRIM(DisplayName)) = ?', [$name])->value('ContactTypeID');
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ شماره' . ' ' . bin2hex(random_bytes(3)), 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    /** موبایلی که در دادهٔ فعلی ثبت نشده (تا قاعدهٔ یکتایی دخالت نکند) */
    private function freshMobile(): string
    {
        do {
            $n = '0935' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
            $exists = DB::selectOne('SELECT COUNT(*) AS c FROM dbo.CrmContacts WHERE dbo.fn_Crm_NormalizeMobile(ContactValue) = ?', [$n])->c;
        } while ((int) $exists > 0);

        return $n;
    }

    private function save(string $type, string $value)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $this->party(), 'contactTypeId' => $this->typeId($type), 'contactValue' => $value,
        ]);
    }

    public function test_plain_digit_mobile_is_accepted(): void
    {
        $m = $this->freshMobile();
        $this->save('موبایل', $m)->assertOk()->assertJson(['success' => true]);
        $this->assertSame($m, DB::table('CrmContacts')->where('ContactValue', $m)->value('ContactValue'));
    }

    public function test_mobile_with_any_non_digit_character_is_rejected(): void
    {
        $m = $this->freshMobile();
        $local = substr($m, 1);
        $persian = strtr($m, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $variants = [
            '+98' . $local, '+98 ' . $local, substr($m, 0, 4) . ' ' . substr($m, 4), substr($m, 0, 4) . '-' . substr($m, 4),
            '(' . substr($m, 0, 4) . ')' . substr($m, 4), $persian, substr($m, 0, 4) . '.' . substr($m, 4), $m . 'a',
        ];
        foreach ($variants as $bad) {
            $res = $this->save('موبایل', $bad);
            $res->assertJson(['success' => false]);
            $this->assertStringContainsString('فقط باید شاملِ رقم باشد', (string) $res->json('message'), $bad);
        }
        $this->assertSame(0, DB::table('CrmContacts')->whereIn('ContactValue', $variants)->count());
    }

    public function test_mobile_with_wrong_digit_count_or_prefix_is_rejected(): void
    {
        foreach (['0912123456', '091212345678', '02112345678', '9121234567', '00989121234567'] as $bad) {
            $res = $this->save('موبایل', $bad);
            $res->assertJson(['success' => false]);
            $this->assertStringContainsString('شمارهٔ موبایل باید ۱۱ رقم باشد', (string) $res->json('message'), $bad);
        }
    }

    public function test_landline_and_fax_accept_plain_11_digits_with_area_code_or_8_digits(): void
    {
        $this->save('تلفن', '02155955160')->assertOk()->assertJson(['success' => true]);
        $this->save('تلفن', '22334455')->assertOk()->assertJson(['success' => true]);
        $this->save('فکس', '02112345678')->assertOk()->assertJson(['success' => true]);
        $this->save('فکس', '88776655')->assertOk()->assertJson(['success' => true]);
    }

    public function test_landline_and_fax_with_non_digit_characters_are_rejected(): void
    {
        foreach (['تلفن', 'فکس'] as $type) {
            foreach (['021-55955160', '021 5595 5160', '+982155955160', '(021)55955160', '۰۲۱۵۵۹۵۵۱۶۰', '۲۲۳۳۴۴۵۵', 'تلفن ندارد'] as $bad) {
                $res = $this->save($type, $bad);
                $res->assertJson(['success' => false]);
                $this->assertStringContainsString('شمارهٔ ' . $type . ' فقط باید شاملِ رقم باشد', (string) $res->json('message'), $bad);
            }
        }
    }

    public function test_landline_and_fax_with_wrong_digit_count_are_rejected(): void
    {
        foreach (['تلفن', 'فکس'] as $type) {
            foreach (['021123456', '0211234567890', '1234567', '0221234', '12345678901'] as $bad) {
                $res = $this->save($type, $bad);
                $res->assertJson(['success' => false]);
                $this->assertStringContainsString('شمارهٔ ' . $type . ' باید ۱۱ رقم', (string) $res->json('message'), $bad);
            }
        }
    }

    public function test_other_contact_types_are_not_digit_checked(): void
    {
        $this->save('ایمیل', 'info@example.com')->assertOk()->assertJson(['success' => true]);
        $this->save('وب‌سایت', 'https://example.com')->assertOk()->assertJson(['success' => true]);
    }
}
