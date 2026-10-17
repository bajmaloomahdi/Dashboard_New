<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * فایل‌هایِ پیوستِ طرف‌حساب (CrmPartyAttachments — تبِ «ضمائم و سایر ویژگی‌ها»، غیر از تصاویر):
 * قواعدِ نوع/حجم همان پیوستِ تعاملات (۱۰ مگابایت، انواعِ اجرایی مسدود)، دانلود با نامِ اصلی از Routeِ
 * دارایِ دسترسی، حذفِ منطقی (فایل رویِ دیسک می‌ماند).
 */
class CrmPartyAttachmentTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const USER_FULL = 2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

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

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ پیوست', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function upload(int $partyId, array $files, ?int $user = null)
    {
        return $this->as($user ?? self::USER_FULL)->post("/crm/parties/{$partyId}/attachments", ['files' => $files], ['Accept' => 'application/json']);
    }

    private function attachments(int $partyId, ?int $user = null): array
    {
        return $this->as($user ?? self::USER_FULL)->getJson("/crm/parties/{$partyId}/attachments")->assertOk()->json('items');
    }

    public function test_fresh_party_has_no_attachments(): void
    {
        $this->assertSame([], $this->attachments($this->party()));
    }

    public function test_upload_list_and_download_with_original_name(): void
    {
        $partyId = $this->party();

        $this->upload($partyId, [
            UploadedFile::fake()->create('قرارداد ۱۴۰۵.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('report.xlsx', 30),
        ])->assertOk()->assertJson(['success' => true]);

        $items = $this->attachments($partyId);
        $this->assertCount(2, $items);
        $this->assertSame('قرارداد ۱۴۰۵.pdf', $items[0]['FileName']);
        $this->assertSame('pdf', $items[0]['FileExtension']);
        $this->assertSame(120 * 1024, (int) $items[0]['FileSize']);
        $this->assertArrayNotHasKey('FilePath', $items[0]);
        $this->assertStringContainsString('/crm/party-attachments/', $items[0]['DownloadUrl']);

        // فایل رویِ Private Disk با نامِ تصادفی، داخلِ پوشهٔ همین طرف‌حساب
        $path = DB::table('CrmPartyAttachments')->where('PartyAttachmentID', $items[0]['PartyAttachmentID'])->value('FilePath');
        $this->assertStringStartsWith("crm/party-attachments/{$partyId}/", $path);
        $this->assertStringNotContainsString('قرارداد', $path);
        Storage::disk('local')->assertExists($path);

        $res = $this->as(self::USER_FULL)->get($items[0]['DownloadUrl']);
        $res->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_show_page_provides_party_attachments_prop(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->create('doc.docx', 10)])->assertOk();

        $this->as(self::USER_FULL)->get("/crm/parties/{$partyId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Parties/Show')->has('partyAttachments', 1));
    }

    public function test_executable_and_script_files_are_rejected(): void
    {
        $partyId = $this->party();

        foreach (['setup.exe', 'run.php', 'report.php.pdf', 'page.html', 'script.js'] as $name) {
            $this->upload($partyId, [UploadedFile::fake()->create($name, 5)])->assertJson(['success' => false]);
        }
        $this->assertSame([], $this->attachments($partyId));
    }

    public function test_file_larger_than_10mb_is_rejected_and_nothing_is_stored(): void
    {
        $partyId = $this->party();

        $this->upload($partyId, [
            UploadedFile::fake()->create('ok.pdf', 10),
            UploadedFile::fake()->create('big.zip', 10241),
        ])->assertJson(['success' => false]);

        $this->assertSame([], $this->attachments($partyId));
        $this->assertSame([], Storage::disk('local')->allFiles("crm/party-attachments/{$partyId}"));
    }

    public function test_more_than_ten_files_per_request_is_rejected(): void
    {
        $partyId = $this->party();
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->create("f{$i}.txt", 1);
        }

        $this->upload($partyId, $files)->assertJson(['success' => false]);
        $this->assertSame([], $this->attachments($partyId));
    }

    public function test_description_can_be_edited_and_cleared(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->create('a.pdf', 5)])->assertOk();
        $id = $this->attachments($partyId)[0]['PartyAttachmentID'];

        $this->as(self::USER_FULL)->postJson("/crm/party-attachments/{$id}/description", ['description' => '  نسخهٔ امضاشده  '])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame('نسخهٔ امضاشده', $this->attachments($partyId)[0]['Description']);

        $this->as(self::USER_FULL)->postJson("/crm/party-attachments/{$id}/description", ['description' => null])->assertOk();
        $this->assertNull($this->attachments($partyId)[0]['Description']);

        $this->as(self::USER_FULL)->postJson("/crm/party-attachments/{$id}/description", ['description' => str_repeat('x', 501)])
            ->assertStatus(422);
    }

    public function test_delete_is_logical_and_keeps_the_file(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->create('a.pdf', 5)])->assertOk();
        $item = $this->attachments($partyId)[0];
        $path = DB::table('CrmPartyAttachments')->where('PartyAttachmentID', $item['PartyAttachmentID'])->value('FilePath');

        $this->as(self::USER_FULL)->postJson("/crm/party-attachments/{$item['PartyAttachmentID']}/delete")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame([], $this->attachments($partyId));
        $this->assertSame(0, (int) DB::table('CrmPartyAttachments')->where('PartyAttachmentID', $item['PartyAttachmentID'])->value('IsActive'));
        Storage::disk('local')->assertExists($path);

        // فایلِ حذف‌شده دیگر دانلود نمی‌شود و حذفِ دوباره خطا می‌دهد
        $this->as(self::USER_FULL)->get($item['DownloadUrl'])->assertNotFound();
        $this->as(self::USER_FULL)->postJson("/crm/party-attachments/{$item['PartyAttachmentID']}/delete")->assertJson(['success' => false]);
    }

    public function test_view_and_manage_require_permissions(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->create('a.pdf', 5)])->assertOk();
        $item = $this->attachments($partyId)[0];
        $noPerm = $this->permissionlessUser();

        $this->as($noPerm)->getJson("/crm/parties/{$partyId}/attachments")->assertStatus(403);
        $this->as($noPerm)->get($item['DownloadUrl'])->assertStatus(403);
        $this->upload($partyId, [UploadedFile::fake()->create('b.pdf', 5)], $noPerm)->assertStatus(403);
        $this->as($noPerm)->postJson("/crm/party-attachments/{$item['PartyAttachmentID']}/delete")->assertStatus(403);
        $this->as($noPerm)->postJson("/crm/party-attachments/{$item['PartyAttachmentID']}/description", ['description' => 'x'])->assertStatus(403);

        // فقط CRM_VIEW (کاربرِ جدا؛ دسترسی‌ها پیش از اولین درخواستش داده می‌شوند): مشاهده و دانلود بله، تغییر نه
        $viewer = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($viewer, ['CRM_VIEW']);
        $this->assertCount(1, $this->attachments($partyId, $viewer));
        $this->as($viewer)->get($item['DownloadUrl'])->assertOk();
        $this->as($viewer)->postJson("/crm/party-attachments/{$item['PartyAttachmentID']}/delete")->assertStatus(403);
    }

    public function test_attachments_are_scoped_to_their_party(): void
    {
        $partyA = $this->party();
        $partyB = $this->party();
        $this->upload($partyA, [UploadedFile::fake()->create('a.pdf', 5)])->assertOk();

        $this->assertCount(1, $this->attachments($partyA));
        $this->assertSame([], $this->attachments($partyB));
    }

    public function test_general_delete_of_party_is_blocked_by_its_attachments_with_label(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->create('a.pdf', 5)])->assertOk();

        $res = $this->as(self::USER_FULL)->postJson("/crm/delete/party/{$partyId}");
        $res->assertJson(['success' => false]);
        $this->assertStringContainsString('فایل‌هایِ پیوست', (string) $res->json('message'));
    }
}
