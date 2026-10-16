<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use Illuminate\Support\Facades\DB;

/**
 * Renderِ متنِ Letter Template — Phase 3, Phase C.
 *
 * کاملاً مستقل از Condition Engine: هیچ‌جا WorkflowConditionFields،
 * ConditionContextBuilder یا ConditionDataTypeCaster را import/فراخوانی
 * نمی‌کند؛ Castِ مقادیر و تطبیقِ Token هردو محلی و از نو نوشته شده‌اند.
 * `MasterParameters`ِ گزارش‌سازی هم اینجا هیچ نقشی ندارد.
 *
 * فقط Read-Only است — هیچ نوشتنی در DB انجام نمی‌دهد.
 */
class TemplateRenderer
{
    /** فرمتِ رسمیِ Token — دقیقاً هم‌الگو با Codeِ TemplateParameters. */
    private const TOKEN_PATTERN = '/\{\{([A-Z][A-Z0-9_]{1,49})\}\}/';

    public function __construct(private WorkflowStore $store)
    {
    }

    /** @return string[] Codeهایِ یکتایِ یافت‌شده در متن، به ترتیبِ ظهور */
    public function extractTokenCodes(string $text): array
    {
        preg_match_all(self::TOKEN_PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * تمامِ Tokenهایِ دو متن را نسبت به Registryِ TemplateParameters اعتبارسنجی می‌کند.
     * برایِ استفادهٔ مشترک در ذخیرهٔ Template (LetterTemplateService) و در render().
     *
     * @throws WorkflowValidationException اگر Tokenی ناشناخته/غیرفعال/EntityType-ناسازگار باشد
     */
    public function validateTokens(string $subjectTemplate, string $bodyTemplate, string $entityType): void
    {
        $codes = $this->extractTokenCodes($subjectTemplate . ' ' . $bodyTemplate);

        foreach ($codes as $code) {
            $param = $this->store->getTemplateParameterByCode($code);

            if ($param === null) {
                throw new WorkflowValidationException("Tokenِ «{$code}» در Registryِ پارامترها شناخته‌شده نیست.");
            }
            if (! (bool) $param->IsActive) {
                throw new WorkflowValidationException("پارامترِ «{$code}» غیرفعال است و قابلِ‌استفاده در Template نیست.");
            }
            if ($param->EntityType !== null && $param->EntityType !== $entityType) {
                throw new WorkflowValidationException("پارامترِ «{$code}» مخصوصِ نوعِ موجودیتِ دیگری است و با این Template سازگار نیست.");
            }
        }
    }

    /**
     * @param  array<string,mixed>  $formValues  مقادیرِ خامِ فرمِ ثبتِ درخواست (کلید = SourceKey)
     * @return array{subject:string, body:string}
     *
     * @throws WorkflowValidationException اگر Template یافت نشود/غیرفعال باشد، Tokenی نامعتبر باشد،
     *                                      یا مقدارِ یک پارامترِ لازم ارسال نشده باشد
     */
    public function render(int $templateId, array $formValues, int $actorUserId): array
    {
        $template = $this->store->getLetterTemplateById($templateId);
        if ($template === null || ! (bool) $template->IsActive) {
            throw new WorkflowValidationException('قالبِ نامه یافت نشد یا غیرفعال است.');
        }

        // دوباره‌اعتبارسنجیِ Tokenها در لحظهٔ Render — دفاعِ لایه‌دوم، برایِ حالتی که یک
        // پارامتر بعد از Saveِ Template غیرفعال شده باشد.
        $this->validateTokens($template->SubjectTemplate, $template->BodyTemplate, $template->EntityType);

        $values = [];
        foreach ($this->extractTokenCodes($template->SubjectTemplate . ' ' . $template->BodyTemplate) as $code) {
            $param = $this->store->getTemplateParameterByCode($code);

            $raw = match ($param->SourceType) {
                'USER' => $this->resolveUserField($actorUserId, $param->SourceKey),
                'SYSTEM' => $this->resolveSystemField($param->SourceKey),
                'FORM' => array_key_exists($param->SourceKey, $formValues) ? $formValues[$param->SourceKey] : null,
                default => null,
            };

            if ($raw === null || $raw === '') {
                throw new WorkflowValidationException("مقدارِ پارامترِ «{$param->Caption}» ({$code}) ارسال نشده است.");
            }

            $values[$code] = $this->castValue($param->DataType, $raw, $param->Caption);
        }

        return [
            'subject' => $this->substitute($template->SubjectTemplate, $values),
            'body' => $this->substitute($template->BodyTemplate, $values),
        ];
    }

    /**
     * Resolveِ جزئی (Read-Only) برایِ پیش‌نمایشِ اولیهٔ Composer — بدونِ وابستگی به مقدارِ FORM.
     *
     * Tokenهایِ USER/SYSTEM مقدار می‌گیرند؛ Tokenهایِ FORM فقط در `pending` می‌مانند تا کاربر پر کند؛
     * Tokenهایِ USER/SYSTEM که برایِ این کاربر مقدار ندارند (مثلاً کاربرِ بدونِ سمت) در `unresolved`
     * گزارش می‌شوند. `render()` بدونِ تغییر و همچنان All-or-Nothing است (رندرِ نهایی).
     *
     * @return array{resolved:array<string,string>, pending:string[], unresolved:array<int,array{code:string,caption:string}>}
     *
     * @throws WorkflowValidationException اگر Template یافت نشود/غیرفعال باشد یا Tokenِ نامعتبر داشته باشد
     */
    public function resolveNonForm(int $templateId, int $actorUserId): array
    {
        $template = $this->store->getLetterTemplateById($templateId);
        if ($template === null || ! (bool) $template->IsActive) {
            throw new WorkflowValidationException('قالبِ نامه یافت نشد یا غیرفعال است.');
        }

        $this->validateTokens($template->SubjectTemplate, $template->BodyTemplate, $template->EntityType);

        $resolved = [];
        $pending = [];
        $unresolved = [];

        foreach ($this->extractTokenCodes($template->SubjectTemplate . ' ' . $template->BodyTemplate) as $code) {
            $param = $this->store->getTemplateParameterByCode($code);

            if ($param->SourceType === 'FORM') {
                $pending[] = $code;
                continue;
            }

            $raw = match ($param->SourceType) {
                'USER' => $this->resolveUserField($actorUserId, $param->SourceKey),
                'SYSTEM' => $this->resolveSystemField($param->SourceKey),
                default => null,
            };

            if ($raw === null || $raw === '') {
                $unresolved[] = ['code' => $code, 'caption' => $param->Caption];
                continue;
            }

            $resolved[$code] = $this->castValue($param->DataType, $raw, $param->Caption);
        }

        return ['resolved' => $resolved, 'pending' => $pending, 'unresolved' => $unresolved];
    }

    /* ---------- Resolveِ منابع ---------- */

    private function resolveUserField(int $userId, string $sourceKey): ?string
    {
        if ($sourceKey === 'FullName' || $sourceKey === 'UserCode') {
            $row = DB::selectOne('SELECT FullName, UserCode FROM dbo.Users WHERE UserID = ?', [$userId]);

            return $row ? ($sourceKey === 'FullName' ? $row->FullName : $row->UserCode) : null;
        }

        if ($sourceKey === 'PositionName' || $sourceKey === 'UnitName') {
            // آخرین سمتِ فعالِ کاربر — دقیقاً همان الگویِ sp_Wf_ResolveAssignees/DIRECT_MANAGER
            $row = DB::selectOne(
                'SELECT TOP 1 p.PositionName, u.UnitName
                 FROM dbo.UserPositions up
                 JOIN dbo.Positions p ON p.PositionID = up.PositionID
                 JOIN dbo.OrganizationalUnits u ON u.UnitID = up.UnitID
                 WHERE up.UserID = ? AND up.IsActive = 1
                 ORDER BY up.CreateDate DESC, up.UserPositionID DESC',
                [$userId]
            );

            return $row ? ($sourceKey === 'PositionName' ? $row->PositionName : $row->UnitName) : null;
        }

        return null;
    }

    private function resolveSystemField(string $sourceKey): ?string
    {
        return match ($sourceKey) {
            'Today' => $this->todayJalali(),
            default => null,
        };
    }

    /* ---------- Cast — مستقل، بدونِ ConditionDataTypeCaster ---------- */

    private function castValue(string $dataType, mixed $raw, string $caption): string
    {
        $str = trim((string) $raw);

        return match ($dataType) {
            'STRING' => $str,
            // PersianDateInput مقدارِ فرم را همیشه ISOِ میلادی (YYYY-MM-DD) می‌دهد؛ متنِ نهاییِ
            // Renderشده باید شمسی باشد، پس همین‌جا (با همان gregorianToJalali موجود) تبدیل می‌شود.
            'DATE' => $this->formatDateValue($str),
            // زمان تقویم‌محور نیست؛ فقط فرمتِ HH:mm اعتبارسنجی و همان‌طور بازگردانده می‌شود.
            'TIME' => $this->formatTimeValue($str, $caption),
            'INTEGER' => (function () use ($str, $caption) {
                $v = filter_var($str, FILTER_VALIDATE_INT);
                if ($v === false) {
                    throw new WorkflowValidationException("مقدارِ پارامترِ «{$caption}» باید یک عددِ صحیح باشد.");
                }

                return $this->formatThousands((string) $v);
            })(),
            'DECIMAL' => (function () use ($str, $caption) {
                if (! preg_match('/^-?\d+(\.\d+)?$/', $str)) {
                    throw new WorkflowValidationException("مقدارِ پارامترِ «{$caption}» باید یک عددِ اعشاریِ معتبر باشد.");
                }

                return $this->formatThousands($str); // رشته‌ای، بدونِ تبدیل به float — برایِ جلوگیری از خطایِ دقت
            })(),
            default => $str,
        };
    }

    /**
     * جداکنندهٔ سه‌رقمیِ قسمتِ صحیح — کاملاً رشته‌ای، بدونِ عبور از int/float، تا مقدارِ
     * DECIMAL هرگز دچارِ خطایِ دقت نشود (علامتِ منفی و رقم‌هایِ اعشاری دست‌نخورده می‌مانند).
     */
    private function formatThousands(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$intPart, $fracPart] = array_pad(explode('.', $unsigned, 2), 2, null);

        $grouped = strrev(implode(',', str_split(strrev($intPart), 3)));

        return ($negative ? '-' : '') . $grouped . ($fracPart !== null ? ".{$fracPart}" : '');
    }

    /** اعتبارسنجیِ فرمتِ HH:mm (خروجیِ AntD TimePicker) — بدونِ هیچ تبدیلِ تقویمی. */
    private function formatTimeValue(string $value, string $caption): string
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            throw new WorkflowValidationException("مقدارِ پارامترِ «{$caption}» باید به‌صورتِ ساعت:دقیقهٔ معتبر (HH:mm) باشد.");
        }

        return $value;
    }

    /** ISOِ میلادیِ (YYYY-MM-DD) خروجیِ PersianDateInput را به نمایشِ شمسی (YYYY/MM/DD) تبدیل می‌کند. */
    private function formatDateValue(string $value): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $value; // فرمتِ غیرِمنتظره — بدونِ تبدیل، همان مقدارِ خام
        }

        [$jy, $jm, $jd] = $this->gregorianToJalali((int) $m[1], (int) $m[2], (int) $m[3]);

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /* ---------- تاریخِ جلالیِ امروز — مستقل، بدونِ کتابخانهٔ خارجی ---------- */

    private function todayJalali(): string
    {
        [$jy, $jm, $jd] = $this->gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /** @return array{0:int,1:int,2:int} [جلالی سال، ماه، روز] */
    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /* ---------- جایگزینیِ متنی ---------- */

    private function substitute(string $text, array $values): string
    {
        return preg_replace_callback(
            self::TOKEN_PATTERN,
            fn ($m) => array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0],
            $text
        );
    }
}
