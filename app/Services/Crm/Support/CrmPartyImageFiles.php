<?php

namespace App\Services\Crm\Support;

use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فایلِ تصویرِ طرف‌حساب رویِ Private Disk ('local' → storage/app/private/crm/party-images/{PartyID}/{random}.{ext}).
 * دقیقاً هم‌الگو با CrmBrandLogo (فقط PNG/JPG/WebP، بدونِ SVG، چکِ MIMEِ واقعی + ابعاد)، با این تفاوت
 * که اینجا رابطه ۱:N است (هر Party چند تصویر) نه ۱:۱ — منطقِ Validation/Storage مستقل از برند نگه
 * داشته شده تا Gallery طرف‌حساب به لوگوی برند وابسته نباشد. حذف همیشه منطقی است (IsActive=0 در SP)؛
 * این کلاس هیچ متدِ حذفِ فیزیکی ندارد.
 */
class CrmPartyImageFiles
{
    public const DISK = 'local';
    public const DIRECTORY = 'crm/party-images';
    public const MAX_BYTES = 1024 * 1024;
    public const MAX_DIMENSION = 4000;
    public const MAX_FILES = 10;
    public const MAX_DESCRIPTION = 500;

    /** پسوندِ مجاز => MIMEِ متناظر (MIMEِ واقعیِ محتوا باید با پسوند جور باشد) */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** بررسیِ یک فایل پیش از ذخیره (همهٔ فایل‌ها اول بررسی می‌شوند، بعد ذخیره) */
    public function validate(mixed $file): void
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new CrmValidationException('آپلودِ تصویر ناموفق بود.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! array_key_exists($extension, self::TYPES)) {
            throw new CrmValidationException('فرمتِ تصویر باید PNG، JPG یا WebP باشد (SVG مجاز نیست).');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new CrmValidationException('حجمِ تصویر نباید بیشتر از ۱ مگابایت باشد.');
        }
        if ($file->getSize() === 0) {
            throw new CrmValidationException('فایلِ تصویر خالی است.');
        }

        // محتوایِ واقعی: MIME از fileinfo و ساختارِ تصویر از getimagesize — باید با پسوند یکی باشند
        // (فقط بر اساسِ پسوند پذیرفته نمی‌شود — دفاع در برابرِ فایلِ غیرتصویر با پسوندِ جعلی)
        $mime = (string) $file->getMimeType();
        $info = @getimagesize($file->getRealPath());
        if ($mime !== self::TYPES[$extension] || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw new CrmValidationException('محتوایِ فایل با یک تصویرِ PNG/JPG/WebPِ معتبر مطابقت ندارد.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new CrmValidationException('ابعادِ تصویر نباید بیشتر از ۴۰۰۰×۴۰۰۰ پیکسل باشد.');
        }
    }

    /** @return array{path: string, mime: string} */
    public function store(UploadedFile $file, int $partyId): array
    {
        $this->validate($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = self::TYPES[$extension];
        $diskExtension = $extension === 'jpeg' ? 'jpg' : $extension;

        // نامِ رویِ دیسک همیشه تصادفی است — هرگز از ورودیِ خامِ کاربر (نامِ اصلیِ فایل) ساخته نمی‌شود
        $path = $file->storeAs(self::DIRECTORY . '/' . $partyId, Str::random(40) . '.' . $diskExtension, self::DISK);
        if ($path === false) {
            throw new CrmValidationException('ذخیرهٔ تصویر ممکن نشد.');
        }

        return ['path' => $path, 'mime' => $mime];
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /**
     * فقط برایِ Rollbackِ یک Uploadِ ناموفق (فایل‌هایِ رویِ دیسک که هرگز ردیفِ DB نگرفتند) —
     * حذفِ کاربرمحورِ تصویر همیشه منطقی است (IsActive=0 در sp_Crm_DeletePartyImage) و هرگز
     * این متد را صدا نمی‌زند؛ فایلِ یک تصویرِ موفق‌ثبت‌شده هیچ‌وقت از دیسک پاک نمی‌شود.
     */
    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::DIRECTORY . '/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** ImageUrl (Routeِ دارایِ Permission) و حذفِ مسیرِ فیزیکی از ردیف — مسیر هرگز به Frontend نمی‌رود */
    public function present(object $row): object
    {
        $row->ImageUrl = route('crm.party-images.show', ['imageId' => (int) $row->PartyImageID], false);
        unset($row->ImagePath);

        return $row;
    }
}
