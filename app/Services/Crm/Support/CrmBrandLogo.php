<?php

namespace App\Services\Crm\Support;

use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فایلِ لوگویِ برند رویِ Private Disk ('local' → storage/app/private/crm/brands/{random}.{ext}).
 * - فقط PNG/JPG/JPEG/WebP؛ SVG ممنوع (خطرِ XSS)؛ حداکثر ۱ مگابایت و ۴۰۰۰×۴۰۰۰ پیکسل.
 * - هم پسوند و هم محتوایِ واقعیِ فایل (fileinfo + getimagesize) بررسی می‌شود.
 * - مسیرِ فیزیکی هرگز به Frontend نمی‌رود؛ فقط LogoUrl (Routeِ دارایِ Permission) برمی‌گردد.
 */
class CrmBrandLogo
{
    public const DISK = 'local';
    public const DIRECTORY = 'crm/brands';
    public const MAX_BYTES = 1024 * 1024;
    public const MAX_DIMENSION = 4000;

    /** پسوندِ مجاز => MIMEِ متناظر (MIMEِ واقعیِ محتوا باید با پسوند جور باشد) */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** @return array{path: string, mime: string} */
    public function store(UploadedFile $file): array
    {
        $mime = $this->validate($file);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
        $path = $file->storeAs(self::DIRECTORY, Str::random(40) . '.' . $extension, self::DISK);
        if ($path === false) {
            throw new CrmValidationException('ذخیرهٔ فایلِ لوگو ممکن نشد.');
        }

        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::DIRECTORY . '/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /** LogoUrl (با نسخهٔ نام‌فایل برایِ شکستنِ Cache پس از جایگزینی) و حذفِ مسیرِ فیزیکی از ردیف */
    public function present(object $row): object
    {
        $row->LogoUrl = ! empty($row->LogoPath)
            ? route('crm.brands.logo', ['brandId' => (int) $row->BrandID], false) . '?v=' . substr(sha1((string) $row->LogoPath), 0, 12)
            : null;
        unset($row->LogoPath, $row->LogoMimeType);

        return $row;
    }

    /** @return string MIMEِ واقعیِ تأییدشده */
    private function validate(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new CrmValidationException('آپلودِ فایلِ لوگو ناموفق بود.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! array_key_exists($extension, self::TYPES)) {
            throw new CrmValidationException('فرمتِ لوگو باید PNG، JPG یا WebP باشد (SVG مجاز نیست).');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new CrmValidationException('حجمِ لوگو نباید بیشتر از ۱ مگابایت باشد.');
        }

        // محتوایِ واقعی: MIME از fileinfo و ساختارِ تصویر از getimagesize — باید با پسوند یکی باشند
        $mime = (string) $file->getMimeType();
        $info = @getimagesize($file->getRealPath());
        if ($mime !== self::TYPES[$extension] || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw new CrmValidationException('محتوایِ فایل با یک تصویرِ PNG/JPG/WebPِ معتبر مطابقت ندارد.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new CrmValidationException('ابعادِ لوگو نباید بیشتر از ۴۰۰۰×۴۰۰۰ پیکسل باشد.');
        }

        return $mime;
    }
}
