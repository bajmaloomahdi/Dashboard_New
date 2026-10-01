<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * پیوستِ تعاملاتِ CRM (CrmInteractionAttachments؛ فایل رویِ Private Disk: crm/interactions/{InteractionID}/…):
 * - افزودنِ یک/چند فایل با توضیحاتِ جداگانه؛ نمایش در فهرستِ تعاملات بدونِ مسیرِ فیزیکی.
 * - دانلود با CRM_VIEW و نامِ اصلیِ فایل؛ حذف با CRM_MANAGE_PARTIES (ردیف + فایل).
 * - سقفِ ۱۰ مگابایت (استانداردِ پروژه)، حداکثر ۱۰ فایل، انواعِ اجرایی/اسکریپتی مسدود.
 * - هر خطا در میانهٔ ثبت → هیچ ردیف/فایلی از همان درخواست باقی نمی‌ماند.
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)؛ 3 → بدونِ Permissionهایِ CRM
 */
class CrmInteractionAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

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

    /** InteractionTypeID از رویِ Codeِ Master Data (تعاملات اکنون Master Data هستند، نه Enumِ ثابت). */
    private function typeId(string $code): int
    {
        return (int) DB::selectOne('SELECT InteractionTypeID FROM dbo.CrmInteractionTypes WHERE Code = ?', [$code])->InteractionTypeID;
    }

    private function interaction(int $party): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/interactions', [
            'partyId' => $party, 'interactionTypeId' => $this->typeId('CALL'), 'subject' => 'تماسِ پیوست', 'interactionDate' => '2026-09-27 10:00:00',
        ])->assertOk()->json('interactionId');
    }

    private function upload(int $interactionId, array $files, array $descriptions = [], int $user = self::USER_FULL)
    {
        return $this->as($user)->post("/crm/interactions/{$interactionId}/attachments", [
            'attachments' => $files, 'descriptions' => $descriptions,
        ], ['Accept' => 'application/json']);
    }

    private function items(int $party): array
    {
        return $this->as(self::USER_FULL)->getJson("/crm/interactions?partyId={$party}")->assertOk()->json('items');
    }

    /** @return string[] */
    private function files(): array
    {
        return Storage::disk('local')->allFiles('crm/interactions');
    }

    private function rows(int $interactionId)
    {
        return DB::table('CrmInteractionAttachments')->where('InteractionID', $interactionId)->orderBy('InteractionAttachmentID')->get();
    }

    private function rejected($response, string $contains): void
    {
        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString($contains, (string) $response->json('message'));
    }

    public function test_upload_multiple_files_with_descriptions(): void
    {
        $party = $this->party();
        $id = $this->interaction($party);

        $this->upload($id, [
            UploadedFile::fake()->create('قرارداد نهایی.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->create('photo.JPG', 50, 'image/jpeg'),
        ], ['نسخهٔ امضاشده', ''])->assertOk()->assertJsonPath('success', true);

        $rows = $this->rows($id);
        $this->assertCount(2, $rows);
        $this->assertSame('قرارداد نهایی.pdf', $rows[0]->FileName);
        $this->assertSame('pdf', $rows[0]->FileExtension);
        $this->assertSame('نسخهٔ امضاشده', $rows[0]->Description);
        $this->assertNull($rows[1]->Description);
        $this->assertSame('jpg', $rows[1]->FileExtension);
        foreach ($rows as $row) {
            $this->assertStringStartsWith("crm/interactions/{$id}/", $row->FilePath);
            $this->assertStringNotContainsString('قرارداد', $row->FilePath); // نامِ واقعیِ کاربر رویِ دیسک نیست
            Storage::disk('local')->assertExists($row->FilePath);
        }
        $this->assertCount(2, $this->files());
    }

    public function test_list_shows_attachments_without_physical_path(): void
    {
        $party = $this->party();
        $id = $this->interaction($party);
        $other = $this->interaction($party);
        $this->upload($id, [UploadedFile::fake()->create('a.pdf', 10)], ['توضیحِ الف'])->assertOk();

        $items = collect($this->items($party))->keyBy('InteractionID');
        $atts = $items[$id]['Attachments'];
        $this->assertCount(1, $atts);
        $this->assertSame('a.pdf', $atts[0]['FileName']);
        $this->assertSame('توضیحِ الف', $atts[0]['Description']);
        $this->assertSame("/crm/interaction-attachments/{$atts[0]['InteractionAttachmentID']}/download", $atts[0]['DownloadUrl']);
        $this->assertArrayNotHasKey('FilePath', $atts[0]);
        $this->assertSame([], $items[$other]['Attachments']);

        // صفحهٔ جزئیاتِ طرف‌حساب (Inertia) هم مسیرِ فیزیکی را افشا نمی‌کند
        $path = $this->rows($id)[0]->FilePath;
        $html = $this->as(self::USER_FULL)->get("/crm/parties/{$party}")->assertOk()->getContent();
        $this->assertStringNotContainsString(basename($path), $html);
    }

    public function test_download_with_original_name_and_permission(): void
    {
        $party = $this->party();
        $id = $this->interaction($party);
        $this->upload($id, [UploadedFile::fake()->createWithContent('گزارش.txt', 'hello')])->assertOk();
        $attId = $this->rows($id)[0]->InteractionAttachmentID;

        $res = $this->as(self::USER_FULL)->get("/crm/interaction-attachments/{$attId}/download")->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('گزارش.txt'), (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('hello', $res->streamedContent());

        $this->as(self::USER_NOPERM)->get("/crm/interaction-attachments/{$attId}/download")->assertForbidden();
        $this->as(self::USER_FULL)->get('/crm/interaction-attachments/999999999/download')->assertNotFound();
    }

    public function test_delete_removes_row_and_file(): void
    {
        $party = $this->party();
        $id = $this->interaction($party);
        $this->upload($id, [UploadedFile::fake()->create('a.pdf', 10), UploadedFile::fake()->create('b.pdf', 10)])->assertOk();
        [$a, $b] = $this->rows($id)->all();

        $this->as(self::USER_NOPERM)->postJson("/crm/interaction-attachments/{$a->InteractionAttachmentID}/delete")->assertForbidden();

        $this->as(self::USER_FULL)->postJson("/crm/interaction-attachments/{$a->InteractionAttachmentID}/delete")->assertOk()->assertJsonPath('success', true);
        Storage::disk('local')->assertMissing($a->FilePath);
        Storage::disk('local')->assertExists($b->FilePath);
        $this->assertCount(1, $this->rows($id));

        $this->rejected($this->as(self::USER_FULL)->postJson("/crm/interaction-attachments/{$a->InteractionAttachmentID}/delete"), 'یافت نشد');
        $this->as(self::USER_FULL)->get("/crm/interaction-attachments/{$a->InteractionAttachmentID}/download")->assertNotFound();
    }

    public function test_upload_requires_manage_permission(): void
    {
        $id = $this->interaction($this->party());
        $this->upload($id, [UploadedFile::fake()->create('a.pdf', 10)], [], self::USER_NOPERM)->assertForbidden();
        $this->assertSame([], $this->files());
    }

    public function test_oversize_file_rejected_and_nothing_saved(): void
    {
        $id = $this->interaction($this->party());
        $this->rejected($this->upload($id, [
            UploadedFile::fake()->create('ok.pdf', 10),
            UploadedFile::fake()->create('big.pdf', 10241),
        ]), '۱۰ مگابایت');
        $this->assertCount(0, $this->rows($id));
        $this->assertSame([], $this->files());

        $this->upload($id, [UploadedFile::fake()->create('edge.pdf', 10240)])->assertOk();
    }

    public function test_blocked_types_rejected(): void
    {
        $id = $this->interaction($this->party());
        foreach (['shell.php', 'run.exe', 'page.html', 'icon.svg', 'report.php.pdf', 'script.js', '.htaccess'] as $name) {
            $this->rejected($this->upload($id, [UploadedFile::fake()->createWithContent($name, 'x')]), 'مجاز نیست');
        }
        $this->assertCount(0, $this->rows($id));
        $this->assertSame([], $this->files());
    }

    public function test_empty_and_missing_files_rejected(): void
    {
        $id = $this->interaction($this->party());
        $this->rejected($this->upload($id, []), 'هیچ فایلی');
        $this->rejected($this->upload($id, [UploadedFile::fake()->createWithContent('empty.pdf', '')]), 'خالی');
        $this->rejected($this->as(self::USER_FULL)->post("/crm/interactions/{$id}/attachments", ['attachments' => 'x'], ['Accept' => 'application/json']), 'هیچ فایلی');
    }

    public function test_max_files_per_request(): void
    {
        $id = $this->interaction($this->party());
        $files = array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 1), range(1, 11));
        $this->rejected($this->upload($id, $files), 'حداکثر ۱۰');
        $this->assertSame([], $this->files());
    }

    public function test_long_description_rejected(): void
    {
        $id = $this->interaction($this->party());
        $this->rejected($this->upload($id, [UploadedFile::fake()->create('a.pdf', 1)], [str_repeat('ا', 1001)]), '۱۰۰۰');
        $this->assertSame([], $this->files());
    }

    public function test_missing_interaction_rolls_back_files(): void
    {
        $this->rejected($this->upload(999999999, [UploadedFile::fake()->create('a.pdf', 1), UploadedFile::fake()->create('b.pdf', 1)]), 'تعامل یافت نشد');
        $this->assertSame([], $this->files());
    }

    public function test_attachments_survive_interaction_edit(): void
    {
        $party = $this->party();
        $id = $this->interaction($party);
        $this->upload($id, [UploadedFile::fake()->create('a.pdf', 1)])->assertOk();

        $this->as(self::USER_FULL)->postJson('/crm/interactions', [
            'interactionId' => $id, 'partyId' => $party, 'interactionTypeId' => $this->typeId('CALL'), 'subject' => 'ویرایش‌شده', 'interactionDate' => '2026-09-27 11:00:00',
        ])->assertOk();

        $this->assertCount(1, collect($this->items($party))->firstWhere('InteractionID', $id)['Attachments']);
    }
}
