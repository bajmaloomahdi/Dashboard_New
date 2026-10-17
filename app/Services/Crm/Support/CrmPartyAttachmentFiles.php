<?php

namespace App\Services\Crm\Support;

use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فایل‌هایِ پیوستِ طرف‌حساب (غیر از تصاویرِ Gallery) رویِ Private Disk
 * ('local' → storage/app/private/crm/party-attachments/{PartyID}/{random}.{ext}).
 * قواعدِ نوع/حجم/نام دقیقاً همان پیوستِ تعاملات است (CrmInteractionAttachmentFiles: حداکثر ۱۰ مگابایت،
 * انواعِ اجرایی/اسکریپتی مسدود، نامِ واقعی فقط در DB، نامِ رویِ دیسک تصادفی، دانلود همیشه attachment).
 * حذف همیشه منطقی است (IsActive=0 در SP)؛ متدِ delete فقط برایِ Rollbackِ یک Uploadِ ناموفق است.
 */
class CrmPartyAttachmentFiles
{
    public const DISK = 'local';
    public const DIRECTORY = 'crm/party-attachments';
    public const MAX_FILES = 10;
    public const MAX_DESCRIPTION = 500;

    public function __construct(private CrmInteractionAttachmentFiles $rules)
    {
    }

    /** بررسیِ یک فایل پیش از ذخیره (همان قواعدِ پیوستِ تعاملات) */
    public function validate(mixed $file): void
    {
        $this->rules->validate($file);
    }

    /** @return array{path: string, name: string, extension: ?string, size: int} */
    public function store(UploadedFile $file, int $partyId): array
    {
        $this->validate($file);

        $name = $this->rules->cleanName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,20}$/', $extension) ? $extension : null;

        $diskName = Str::random(40) . ($extension ? '.' . $extension : '');
        $path = $file->storeAs(self::DIRECTORY . '/' . $partyId, $diskName, self::DISK);
        if ($path === false) {
            throw new CrmValidationException("ذخیرهٔ فایلِ «{$name}» ممکن نشد.");
        }

        return ['path' => $path, 'name' => $name, 'extension' => $extension, 'size' => (int) $file->getSize()];
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /** فقط برایِ Rollbackِ فایل‌هایِ همین درخواست که هرگز ردیفِ DB نگرفتند. */
    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::DIRECTORY . '/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** DownloadUrl (Routeِ دارایِ Permission) و حذفِ مسیرِ فیزیکی از ردیف — مسیر هرگز به Frontend نمی‌رود */
    public function present(object $row): object
    {
        $row->DownloadUrl = route('crm.party-attachments.download', ['attachmentId' => (int) $row->PartyAttachmentID], false);
        unset($row->FilePath);

        return $row;
    }
}
