<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تصاویرِ طرف‌حساب (CrmPartyImages — تبِ «ضمائم و سایر ویژگی‌ها»، Stage E): Gallery با رابطهٔ
 * ۱:N؛ فقط PNG/JPG/WebP، حداکثر ۱ مگابایت، بررسیِ MIMEِ واقعی + ابعاد (هم‌الگو با CrmBrandLogo).
 * حذف همیشه منطقی است (IsActive=0)؛ فایلِ فیزیکی هرگز پاک نمی‌شود.
 *
 * کاربران: 2 → RoleID=1 (شاملِ CRM_VIEW و CRM_MANAGE_PARTIES)، 3 → بدونِ این دو.
 */
class CrmPartyImageTest extends TestCase
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
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ تصویر', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function upload(int $partyId, array $files, int $user = self::USER_FULL, array $descriptions = [])
    {
        $payload = ['images' => $files];
        if ($descriptions) {
            $payload['descriptions'] = $descriptions;
        }

        return $this->as($user)->post("/crm/parties/{$partyId}/images", $payload, ['Accept' => 'application/json']);
    }

    private function images(int $partyId, int $user = self::USER_FULL): array
    {
        return $this->as($user)->getJson("/crm/parties/{$partyId}/images")->assertOk()->json('items');
    }

    private function updateDescription(int $imageId, ?string $description, int $user = self::USER_FULL)
    {
        return $this->as($user)->postJson("/crm/party-images/{$imageId}/description", ['description' => $description]);
    }

    public function test_attachments_tab_data_is_empty_for_fresh_party(): void
    {
        $partyId = $this->party();

        $this->assertSame([], $this->images($partyId));
    }

    public function test_upload_jpg_succeeds(): void
    {
        $partyId = $this->party();

        $res = $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 200, 150)]);
        $res->assertOk()->assertJson(['success' => true]);

        $items = $this->images($partyId);
        $this->assertCount(1, $items);
        $this->assertNotEmpty($items[0]['ImageUrl']);
        $this->assertArrayNotHasKey('ImagePath', $items[0], 'مسیرِ فیزیکیِ فایل هرگز نباید به Frontend برود.');
    }

    public function test_upload_png_succeeds(): void
    {
        $partyId = $this->party();

        $res = $this->upload($partyId, [UploadedFile::fake()->image('a.png', 200, 150)]);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $this->images($partyId));
    }

    public function test_upload_webp_succeeds(): void
    {
        $partyId = $this->party();

        $res = $this->upload($partyId, [UploadedFile::fake()->image('a.webp', 200, 150)]);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $this->images($partyId));
    }

    public function test_multiple_images_in_one_request_succeed_and_appear_in_gallery(): void
    {
        $partyId = $this->party();

        $res = $this->upload($partyId, [
            UploadedFile::fake()->image('a.jpg', 100, 100),
            UploadedFile::fake()->image('b.png', 100, 100),
            UploadedFile::fake()->image('c.webp', 100, 100),
        ]);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertCount(3, $this->images($partyId));
    }

    public function test_oversize_file_rejected_and_nothing_saved(): void
    {
        $partyId = $this->party();

        // ۲ مگابایت — بیشتر از سقفِ ۱ مگابایتی
        $big = UploadedFile::fake()->image('big.jpg', 1000, 1000)->size(2048);
        $this->upload($partyId, [$big])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame([], $this->images($partyId));
    }

    public function test_disallowed_format_rejected(): void
    {
        $partyId = $this->party();

        $gif = UploadedFile::fake()->image('a.gif', 100, 100);
        $this->upload($partyId, [$gif])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame([], $this->images($partyId));
    }

    public function test_non_image_file_with_fake_extension_is_rejected(): void
    {
        $partyId = $this->party();

        // محتوایِ واقعاً متنی، فقط پسوندش .jpg است — باید با بررسیِ MIMEِ واقعی رد شود
        $fake = UploadedFile::fake()->createWithContent('not-an-image.jpg', 'این یک تصویر نیست، فقط متن است.');
        $this->upload($partyId, [$fake])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame([], $this->images($partyId));
    }

    public function test_delete_is_soft_and_image_disappears_from_gallery(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->as(self::USER_FULL)->postJson("/crm/party-images/{$imageId}/delete")->assertOk()->assertJson(['success' => true]);

        $this->assertSame([], $this->images($partyId));

        $row = DB::selectOne('SELECT IsActive, ImagePath FROM dbo.CrmPartyImages WHERE PartyImageID = ?', [$imageId]);
        $this->assertNotNull($row, 'ردیف نباید فیزیکاً حذف شود.');
        $this->assertEquals(0, $row->IsActive);
        $this->assertTrue(Storage::disk('local')->exists($row->ImagePath), 'فایلِ فیزیکی نباید پاک شود (فقط حذفِ منطقی).');
    }

    public function test_deleted_image_show_route_returns_404(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->as(self::USER_FULL)->postJson("/crm/party-images/{$imageId}/delete")->assertOk();

        $this->as(self::USER_FULL)->get("/crm/party-images/{$imageId}")->assertStatus(404);
        // حذفِ دوباره هم باید شکست بخورد (ردیف دیگر IsActive=1 نیست)
        $this->as(self::USER_FULL)->postJson("/crm/party-images/{$imageId}/delete")->assertJson(['success' => false]);
    }

    public function test_view_requires_crm_view_permission(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->as(self::USER_NOPERM)->getJson("/crm/parties/{$partyId}/images")->assertStatus(403);
        $this->as(self::USER_NOPERM)->get("/crm/party-images/{$imageId}")->assertStatus(403);
    }

    public function test_upload_and_delete_require_manage_permission(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->upload($partyId, [UploadedFile::fake()->image('b.jpg', 100, 100)], self::USER_NOPERM)->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson("/crm/party-images/{$imageId}/delete")->assertStatus(403);

        // وضعیت دست‌نخورده مانده (همچنان همان ۱ تصویر، نه حذف‌شده نه اضافه‌شده)
        $this->assertCount(1, $this->images($partyId));
    }

    public function test_nonexistent_image_id_does_not_leak_or_crash(): void
    {
        $this->as(self::USER_FULL)->get('/crm/party-images/999999999')->assertStatus(404);
        $this->as(self::USER_FULL)->postJson('/crm/party-images/999999999/delete')->assertJson(['success' => false]);
    }

    public function test_multiple_images_with_independent_descriptions_are_each_linked_to_their_own_image(): void
    {
        $partyId = $this->party();

        $this->upload($partyId, [
            UploadedFile::fake()->image('a.jpg', 100, 100),
            UploadedFile::fake()->image('b.png', 100, 100),
        ], self::USER_FULL, ['توضیحِ تصویرِ اول', 'توضیحِ تصویرِ دوم'])->assertOk()->assertJson(['success' => true]);

        $items = $this->images($partyId);
        $this->assertCount(2, $items);
        $descriptions = array_column($items, 'Description');
        $this->assertContains('توضیحِ تصویرِ اول', $descriptions);
        $this->assertContains('توضیحِ تصویرِ دوم', $descriptions);
        $this->assertNotSame($descriptions[0], $descriptions[1]);
    }

    public function test_upload_without_descriptions_still_succeeds_with_null_description(): void
    {
        $partyId = $this->party();

        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk()->assertJson(['success' => true]);

        $items = $this->images($partyId);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['Description']);
    }

    public function test_created_by_name_is_the_uploading_user(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();

        $uploader = User::find(self::USER_FULL);
        $items = $this->images($partyId);
        $this->assertSame(trim($uploader->FirstName . ' ' . $uploader->LastName), $items[0]['CreatedByName']);
    }

    public function test_edit_description_updates_only_that_image(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [
            UploadedFile::fake()->image('a.jpg', 100, 100),
            UploadedFile::fake()->image('b.jpg', 100, 100),
        ], self::USER_FULL, ['توضیحِ اول', 'توضیحِ دوم'])->assertOk();

        $items = $this->images($partyId);
        $firstId = (int) $items[0]['PartyImageID'];
        $secondId = (int) $items[1]['PartyImageID'];

        $this->updateDescription($firstId, 'توضیحِ ویرایش‌شده')->assertOk()->assertJson(['success' => true]);

        $after = collect($this->images($partyId))->keyBy('PartyImageID');
        $this->assertSame('توضیحِ ویرایش‌شده', $after[$firstId]['Description']);
        $this->assertNotSame('توضیحِ ویرایش‌شده', $after[$secondId]['Description']);
    }

    public function test_edit_description_can_clear_it_to_null(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)], self::USER_FULL, ['اولیه'])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->updateDescription($imageId, null)->assertOk()->assertJson(['success' => true]);

        $this->assertNull($this->images($partyId)[0]['Description']);
    }

    public function test_edit_description_requires_manage_permission(): void
    {
        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];

        $this->updateDescription($imageId, 'دستکاری', self::USER_NOPERM)->assertStatus(403);
        $this->assertNull($this->images($partyId)[0]['Description']);
    }

    /** دستکاریِ PartyImageID نباید روی تصویرِ طرف‌حسابِ دیگری اثر بگذارد — هر ویرایش فقط همان یک ردیف را تغییر می‌دهد. */
    public function test_edit_description_cannot_affect_an_image_of_a_different_party(): void
    {
        $partyA = $this->party();
        $partyB = $this->party();
        $this->upload($partyA, [UploadedFile::fake()->image('a.jpg', 100, 100)], self::USER_FULL, ['متعلق به A'])->assertOk();
        $this->upload($partyB, [UploadedFile::fake()->image('b.jpg', 100, 100)], self::USER_FULL, ['متعلق به B'])->assertOk();

        $imageIdOfA = (int) $this->images($partyA)[0]['PartyImageID'];
        $imageIdOfB = (int) $this->images($partyB)[0]['PartyImageID'];

        $this->updateDescription($imageIdOfA, 'تغییرِ عمدیِ A')->assertOk();

        $this->assertSame('تغییرِ عمدیِ A', $this->images($partyA)[0]['Description']);
        $this->assertSame('متعلق به B', $this->images($partyB)[0]['Description']);
        $this->assertNotEquals($imageIdOfA, $imageIdOfB);
    }

    public function test_edit_description_rejects_nonexistent_or_deleted_image(): void
    {
        $this->updateDescription(999999999, 'هرچیزی')->assertJson(['success' => false]);

        $partyId = $this->party();
        $this->upload($partyId, [UploadedFile::fake()->image('a.jpg', 100, 100)])->assertOk();
        $imageId = (int) $this->images($partyId)[0]['PartyImageID'];
        $this->as(self::USER_FULL)->postJson("/crm/party-images/{$imageId}/delete")->assertOk();

        $this->updateDescription($imageId, 'بعدِ حذف')->assertJson(['success' => false]);
    }

    public function test_other_party_tabs_and_interactions_unaffected_by_images_feature(): void
    {
        $partyId = $this->party();
        $typeId = (int) DB::selectOne("SELECT InteractionTypeID FROM dbo.CrmInteractionTypes WHERE Code = 'CALL'")->InteractionTypeID;

        $this->as(self::USER_FULL)->postJson('/crm/interactions', [
            'partyId' => $partyId, 'interactionTypeId' => $typeId, 'subject' => 'بدونِ Regression', 'interactionDate' => '2026-10-01 10:00:00',
        ])->assertOk()->assertJson(['success' => true]);

        $props = $this->as(self::USER_FULL)->get("/crm/parties/{$partyId}")->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['interactions']);
        $this->assertSame([], $props['partyImages']);
    }
}
