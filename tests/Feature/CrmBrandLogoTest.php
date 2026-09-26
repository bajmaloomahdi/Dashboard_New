<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * لوگویِ برند (Private Disk 'local' → crm/brands/…؛ LogoPath/LogoMimeType در CrmBrands):
 * - ایجاد با/بدونِ لوگو، جایگزینی و حذف (فایلِ قبلی بعد از ذخیرهٔ موفق پاک می‌شود).
 * - خطایِ SP → فایلِ جدید پاک و فایلِ قبلی دست‌نخورده.
 * - فقط PNG/JPG/WebP؛ SVG/پسوندِ جعلی/حجم/ابعادِ بیش از حد رد می‌شوند (پیامِ فارسی + success=false).
 * - Routeِ لوگو با CRM_VIEW؛ LogoUrl برمی‌گردد و مسیرِ فیزیکی هرگز.
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)؛ 3 → بدونِ Permissionهایِ CRM
 */
class CrmBrandLogoTest extends TestCase
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

    private function uniq(string $prefix = 'برندِ لوگو'): string
    {
        return $prefix . ' ' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function save(array $body)
    {
        return $this->as(self::USER_FULL)->post('/crm/brands', $body, ['Accept' => 'application/json']);
    }

    private function row(int $brandId): object
    {
        return DB::table('CrmBrands')->where('BrandID', $brandId)->first();
    }

    /** @return string[] */
    private function files(): array
    {
        return Storage::disk('local')->allFiles('crm/brands');
    }

    private function rejected($response, string $contains): void
    {
        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString($contains, (string) $response->json('message'));
    }

    public function test_create_without_logo_keeps_logo_empty(): void
    {
        $id = $this->save(['name' => $this->uniq()])->assertOk()->json('brandId');

        $row = $this->row($id);
        $this->assertNull($row->LogoPath);
        $this->assertNull($row->LogoMimeType);
        $this->assertSame([], $this->files());
    }

    public function test_create_with_logo_stores_file_on_private_disk(): void
    {
        $res = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.png', 300, 120)])->assertOk();
        $this->assertArrayNotHasKey('OldLogoPath', $res->json());

        $row = $this->row($res->json('brandId'));
        $this->assertSame('image/png', $row->LogoMimeType);
        $this->assertStringStartsWith('crm/brands/', $row->LogoPath);
        $this->assertStringEndsWith('.png', $row->LogoPath);
        Storage::disk('local')->assertExists($row->LogoPath);
        $this->assertSame([$row->LogoPath], $this->files());
    }

    public function test_jpeg_and_webp_are_accepted(): void
    {
        $jpg = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.jpeg', 50, 50)])->assertOk()->json('brandId');
        $this->assertSame('image/jpeg', $this->row($jpg)->LogoMimeType);
        $this->assertStringEndsWith('.jpg', $this->row($jpg)->LogoPath);

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD بدونِ WebP');
        }
        $webp = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.webp', 50, 50)])->assertOk()->json('brandId');
        $this->assertSame('image/webp', $this->row($webp)->LogoMimeType);
    }

    public function test_edit_without_logo_fields_keeps_existing_logo(): void
    {
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('brandId');
        $path = $this->row($id)->LogoPath;

        $this->save(['brandId' => $id, 'name' => $name, 'description' => 'توضیحِ جدید'])->assertOk();

        $this->assertSame($path, $this->row($id)->LogoPath);
        Storage::disk('local')->assertExists($path);
    }

    public function test_replace_deletes_old_file_after_successful_save(): void
    {
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('old.png')])->assertOk()->json('brandId');
        $old = $this->row($id)->LogoPath;

        $this->save(['brandId' => $id, 'name' => $name, 'logo' => UploadedFile::fake()->image('new.jpg')])->assertOk();

        $row = $this->row($id);
        $this->assertNotSame($old, $row->LogoPath);
        $this->assertSame('image/jpeg', $row->LogoMimeType);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($row->LogoPath);
        $this->assertSame([$row->LogoPath], $this->files());
    }

    public function test_remove_clears_columns_and_deletes_file(): void
    {
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('brandId');
        $old = $this->row($id)->LogoPath;

        $this->save(['brandId' => $id, 'name' => $name, 'removeLogo' => '1'])->assertOk();

        $row = $this->row($id);
        $this->assertNull($row->LogoPath);
        $this->assertNull($row->LogoMimeType);
        Storage::disk('local')->assertMissing($old);
        $this->assertSame([], $this->files());
    }

    public function test_sp_failure_on_create_leaves_no_orphan_file(): void
    {
        $name = $this->uniq();
        $this->save(['name' => $name])->assertOk();

        $this->rejected($this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('logo.png')]), 'قبلاً ثبت شده');
        $this->assertSame([], $this->files());
    }

    public function test_sp_failure_on_replace_keeps_old_logo_and_removes_new_file(): void
    {
        $taken = $this->uniq();
        $this->save(['name' => $taken])->assertOk();
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('old.png')])->assertOk()->json('brandId');
        $old = $this->row($id)->LogoPath;

        // نامِ تکراری → SP رد می‌کند
        $this->rejected($this->save(['brandId' => $id, 'name' => $taken, 'logo' => UploadedFile::fake()->image('new.png')]), 'قبلاً ثبت شده');

        $this->assertSame($old, $this->row($id)->LogoPath);
        Storage::disk('local')->assertExists($old);
        $this->assertSame([$old], $this->files());
    }

    public function test_invalid_extension_is_rejected(): void
    {
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')]), 'PNG');
        $this->assertSame([], $this->files());
    }

    public function test_svg_is_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => $svg]), 'SVG');
        $this->assertSame([], $this->files());
    }

    public function test_fake_extension_content_is_rejected(): void
    {
        // متن با پسوندِ png
        $text = UploadedFile::fake()->createWithContent('logo.png', 'this is not an image');
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => $text]), 'محتوایِ فایل');

        // JPEGِ واقعی با پسوندِ png (محتوا با پسوند نمی‌خواند)
        $jpeg = UploadedFile::fake()->image('source.jpg', 20, 20);
        $renamed = UploadedFile::fake()->createWithContent('logo.png', file_get_contents($jpeg->getRealPath()));
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => $renamed]), 'محتوایِ فایل');

        // SVG با پسوندِ png
        $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => $svg]), 'محتوایِ فایل');

        $this->assertSame([], $this->files());
    }

    public function test_oversize_file_is_rejected(): void
    {
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('big.png')->size(1025)]), '۱ مگابایت');
        $this->assertSame([], $this->files());
    }

    public function test_oversize_dimensions_are_rejected(): void
    {
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('wide.png', 4001, 10)]), '۴۰۰۰');
        $this->assertSame([], $this->files());

        $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('edge.png', 4000, 10)])->assertOk();
    }

    public function test_non_file_logo_value_is_rejected(): void
    {
        $this->rejected($this->save(['name' => $this->uniq(), 'logo' => 'not-a-file']), 'لوگو');
    }

    public function test_logo_route_streams_file_with_permission(): void
    {
        $id = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.png', 40, 20)])->assertOk()->json('brandId');

        $res = $this->as(self::USER_FULL)->get("/crm/brands/{$id}/logo")->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
    }

    public function test_logo_route_forbidden_without_permission(): void
    {
        $id = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('brandId');

        $this->as(self::USER_NOPERM)->get("/crm/brands/{$id}/logo")->assertForbidden();
    }

    public function test_logo_route_404_when_missing(): void
    {
        $id = $this->save(['name' => $this->uniq()])->assertOk()->json('brandId');
        $this->as(self::USER_FULL)->get("/crm/brands/{$id}/logo")->assertNotFound();
        $this->as(self::USER_FULL)->get('/crm/brands/999999999/logo')->assertNotFound();

        // ردیفِ دارایِ مسیر ولی فایلِ ناموجود رویِ دیسک
        $id2 = $this->save(['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('brandId');
        Storage::disk('local')->delete($this->row($id2)->LogoPath);
        $this->as(self::USER_FULL)->get("/crm/brands/{$id2}/logo")->assertNotFound();
    }

    public function test_api_returns_logo_url_and_never_the_path(): void
    {
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('brandId');
        $noLogo = $this->save(['name' => $this->uniq()])->assertOk()->json('brandId');
        $path = $this->row($id)->LogoPath;

        $items = collect($this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode('برندِ لوگو'))->assertOk()->json('items'))
            ->keyBy(fn ($r) => (int) $r['BrandID']);

        $this->assertStringStartsWith("/crm/brands/{$id}/logo?v=", $items[$id]['LogoUrl']);
        $this->assertNull($items[$noLogo]['LogoUrl']);
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('LogoPath', $item);
            $this->assertArrayNotHasKey('LogoMimeType', $item);
        }

        // صفحهٔ جزئیات و صفحهٔ کارت‌ها (Inertia props) هم فقط LogoUrl دارند و نامِ فایلِ فیزیکی در HTML نیست
        $file = basename($path);
        $show = $this->as(self::USER_FULL)->get("/crm/brands/{$id}")->assertOk();
        $this->assertStringNotContainsString($file, $show->getContent());
        $brand = $this->inertiaProps($show->getContent())['brand'];
        $this->assertStringStartsWith("/crm/brands/{$id}/logo?v=", $brand['LogoUrl']);
        $this->assertArrayNotHasKey('LogoPath', $brand);
        $this->assertArrayNotHasKey('LogoMimeType', $brand);

        $page = $this->as(self::USER_FULL)->get('/crm/brands-page')->assertOk();
        $this->assertStringNotContainsString($file, $page->getContent());
        foreach ($this->inertiaProps($page->getContent())['brands'] as $b) {
            $this->assertArrayHasKey('LogoUrl', $b);
            $this->assertArrayNotHasKey('LogoPath', $b);
        }
    }

    private function inertiaProps(string $html): array
    {
        $this->assertSame(1, preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m));

        return json_decode(html_entity_decode($m[1]), true)['props'];
    }

    public function test_logo_url_changes_after_replace(): void
    {
        $name = $this->uniq();
        $id = $this->save(['name' => $name, 'logo' => UploadedFile::fake()->image('a.png')])->assertOk()->json('brandId');
        $url1 = collect($this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode($name))->json('items'))->first()['LogoUrl'];

        $this->save(['brandId' => $id, 'name' => $name, 'logo' => UploadedFile::fake()->image('b.png')])->assertOk();
        $url2 = collect($this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode($name))->json('items'))->first()['LogoUrl'];

        $this->assertNotSame($url1, $url2);
    }

    public function test_upload_requires_manage_permission(): void
    {
        $this->as(self::USER_NOPERM)
            ->post('/crm/brands', ['name' => $this->uniq(), 'logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertSame([], $this->files());
    }
}
