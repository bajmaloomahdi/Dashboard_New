<?php

namespace App\Services\Crm\Support;

use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فایلِ پیوستِ تعاملاتِ CRM رویِ Private Disk ('local' → storage/app/private/crm/interactions/{InteractionID}/{random}.{ext}).
 * - سقفِ حجم هم‌سو با استانداردِ فعلیِ پروژه (پیوستِ پیام/پروژه: max:10240 کیلوبایت = ۱۰ مگابایت).
 * - پروژه محدودیتِ نوع ندارد؛ اینجا فقط انواعِ اجرایی/اسکریپتی مسدود می‌شوند (دفاعِ اضافه؛ دانلود هم همیشه attachment است).
 * - نامِ واقعیِ فایلِ کاربر فقط در DB (برایِ نمایش/نامِ دانلود) نگهداری می‌شود؛ نامِ رویِ دیسک تصادفی است
 *   و مسیرِ فیزیکی هرگز به Frontend نمی‌رود (فقط DownloadUrlِ Routeِ دارایِ Permission).
 */
class CrmInteractionAttachmentFiles
{
    public const DISK = 'local';
    public const DIRECTORY = 'crm/interactions';
    public const MAX_BYTES = 10240 * 1024;
    public const MAX_FILES = 10;
    public const MAX_DESCRIPTION = 1000;

    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
        'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'dll', 'cpl', 'sys',
        'sh', 'bash', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'mjs', 'jse', 'wsf', 'wsh', 'hta', 'jar',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py',
        'htaccess', 'htpasswd', 'lnk', 'reg',
    ];

    /** بررسیِ یک فایل پیش از ذخیره (همهٔ فایل‌ها اول بررسی می‌شوند، بعد ذخیره) */
    public function validate(mixed $file): void
    {
        if (! $file instanceof UploadedFile) {
            throw new CrmValidationException('فایلِ پیوست نامعتبر است.');
        }
        if (! $file->isValid()) {
            // شاملِ فایلِ بزرگ‌تر از upload_max_filesizeِ سرور
            throw new CrmValidationException('آپلودِ فایلِ «' . $this->cleanName($file->getClientOriginalName()) . '» ناموفق بود (ممکن است حجمِ آن از سقفِ مجازِ سرور بیشتر باشد).');
        }

        $name = $this->cleanName($file->getClientOriginalName());
        if ($file->getSize() > self::MAX_BYTES) {
            throw new CrmValidationException("حجمِ فایلِ «{$name}» نباید بیشتر از ۱۰ مگابایت باشد.");
        }
        if ($file->getSize() === 0) {
            throw new CrmValidationException("فایلِ «{$name}» خالی است.");
        }

        // همهٔ پسوندهایِ نام بررسی می‌شوند (مثلاً report.php.pdf یا shell.pdf.php)
        $parts = array_slice(explode('.', strtolower($name)), 1);
        if (array_intersect($parts, self::BLOCKED_EXTENSIONS) || $name === '' || str_starts_with($name, '.')) {
            throw new CrmValidationException("نوعِ فایلِ «{$name}» مجاز نیست (فایل‌هایِ اجرایی/اسکریپتی قابلِ پیوست نیستند).");
        }
    }

    /** @return array{path: string, name: string, extension: ?string, size: int} */
    public function store(UploadedFile $file, int $interactionId): array
    {
        $name = $this->cleanName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,20}$/', $extension) ? $extension : null;

        $diskName = Str::random(40) . ($extension ? '.' . $extension : '');
        $path = $file->storeAs(self::DIRECTORY . '/' . $interactionId, $diskName, self::DISK);
        if ($path === false) {
            throw new CrmValidationException("ذخیرهٔ فایلِ «{$name}» ممکن نشد.");
        }

        return ['path' => $path, 'name' => $name, 'extension' => $extension, 'size' => (int) $file->getSize()];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::DIRECTORY . '/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    /** DownloadUrl (Routeِ دارایِ Permission) و حذفِ مسیرِ فیزیکی از ردیف */
    public function present(object $row): object
    {
        $row->DownloadUrl = route('crm.interaction-attachments.download', ['attachmentId' => (int) $row->InteractionAttachmentID], false);
        unset($row->FilePath);

        return $row;
    }

    /** نامِ نمایشی/دانلود: بدونِ مسیر، کاراکترهایِ کنترلی و بیش از ۲۵۵ کاراکتر */
    public function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');

        return mb_substr($name, 0, 255);
    }
}
