<?php

namespace App\Services\Calendar;

use App\Services\Crm\Support\CrmInteractionAttachmentFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * فایل‌هایِ ضمیمهٔ رویدادِ تقویم (پچِ ۱۱۷) رویِ Private Disk ('local' → storage/app/private/calendar/event-attachments/{EventID}/…).
 * قواعدِ نوع/حجم/نام همان پیوست‌هایِ دیگرِ پروژه (CrmInteractionAttachmentFiles: حداکثر ۱۰ مگابایت، انواعِ اجرایی/اسکریپتی مسدود،
 * نامِ واقعی فقط در DB، نامِ رویِ دیسک تصادفی، دانلود همیشه attachment). مسیرِ فیزیکی هرگز به مرورگر نمی‌رود.
 */
class CalendarAttachmentFiles
{
    public const DISK = 'local';
    public const DIRECTORY = 'calendar/event-attachments';
    public const MAX_FILES = 10;

    public function __construct(private CrmInteractionAttachmentFiles $rules)
    {
    }

    /** بررسیِ یک فایل پیش از ذخیره؛ خطا → ValidationException (۴۲۲) */
    public function validate(mixed $file): void
    {
        try {
            $this->rules->validate($file);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['files' => $e->getMessage()]);
        }
    }

    /** @return array{path: string, name: string, extension: ?string, size: int} */
    public function store(UploadedFile $file, int $eventId): array
    {
        $this->validate($file);
        $name = $this->rules->cleanName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,20}$/', $extension) ? $extension : null;

        $diskName = Str::random(40) . ($extension ? '.' . $extension : '');
        $path = $file->storeAs(self::DIRECTORY . '/' . $eventId, $diskName, self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['files' => "ذخیرهٔ فایلِ «{$name}» ممکن نشد."]);
        }

        return ['path' => $path, 'name' => $name, 'extension' => $extension, 'size' => (int) $file->getSize()];
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /** فقط برایِ Rollbackِ فایل‌هایِ همین درخواست که هرگز ردیفِ DB نگرفتند */
    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::DIRECTORY . '/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** آدرسِ دانلود (Routeِ دارایِ کنترلِ دسترسی) و حذفِ مسیرِ فیزیکی از ردیف */
    public function present(object $row): object
    {
        $row->DownloadUrl = route('calendar.event-attachments.download', ['attachmentId' => (int) $row->CalendarEventAttachmentID], false);
        unset($row->FilePath);

        return $row;
    }
}
