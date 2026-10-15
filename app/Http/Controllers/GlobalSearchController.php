<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use App\Services\GlobalSearch\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /global-search (بدنه: q، groups) — جست‌وجویِ سراسری (§3 مشخصات).
 *
 * متنِ جست‌وجو فقط در بدنهٔ درخواست می‌آید، نه در URL (یافتهٔ ۱ ممیزی): در لاگِ وب‌سرور، Referer و
 * تاریخچهٔ مرورگر ثبت نمی‌شود و چون درخواست GET نیست، لاراول آن را در session (_previous.url) هم نگه نمی‌دارد.
 * توکنِ CSRF با میان‌افزارِ استانداردِ گروهِ web بررسی می‌شود (419 بدونِ توکنِ معتبر).
 *
 * زنجیرهٔ کنترل: auth (گروهِ مسیر) ← EnsureGlobalSearchEnabled (404 وقتی خاموش) ← ThrottleGlobalSearch (429)
 * ← پارامتر در URL یا UTF-8 نامعتبر (422) ← اعتبارسنجی (422) ← کاربرِ غیرفعال (403) ← سرویس (دروازهٔ مجوز + دسترسیِ سطحِ رکورد در هر ارائه‌دهنده).
 * پاسخ همیشه JSON با Cache-Control: no-store, private؛ متنِ جست‌وجو در لاگ ثبت نمی‌شود.
 */
class GlobalSearchController extends Controller
{
    /** نویسه‌هایِ کنترلی و جهت‌دهیِ صریح (LRE/RLE/PDF/LRO/RLO و Isolateها) — ردّ می‌شوند (§7.3) */
    private const FORBIDDEN_CHARS = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function __invoke(Request $request, GlobalSearchService $search, AccessContextResolver $resolver): JsonResponse
    {
        // هیچ پارامتری در URL پذیرفته نمی‌شود — تا کلاینتِ اشتباه هم متن را به لاگِ وب‌سرور نفرستد
        if ($request->query->count() > 0) {
            return $this->invalidQuery('پارامترهای جست‌وجو باید در بدنهٔ درخواست ارسال شوند.');
        }

        // پیش از هر regex یا ساختِ JSON: UTF-8 نامعتبر ← 422 (یافتهٔ ۳ ممیزی؛ قبلاً not_regex را دور می‌زد و 500 می‌داد)
        $input = $request->post();
        foreach (['q', 'groups'] as $field) {
            if (is_string($input[$field] ?? null) && ! mb_check_encoding($input[$field], 'UTF-8')) {
                return $this->respond(['message' => 'ورودی نامعتبر است.', 'errors' => [$field => ['متن ارسالی با کدگذاری UTF-8 معتبر نیست.']]], 422);
            }
        }

        $maxRaw = (int) config('global_search.query.max_raw_length', 200);

        $validator = Validator::make($input, [
            'q' => ['required', 'string', 'max:' . $maxRaw, 'not_regex:' . self::FORBIDDEN_CHARS],
            'groups' => ['nullable', 'string', 'max:200'],
        ], [
            'q.required' => 'متن جست‌وجو الزامی است.',
            'q.string' => 'متن جست‌وجو نامعتبر است.',
            'q.max' => 'متن جست‌وجو بیش از حد طولانی است.',
            'q.not_regex' => 'متن جست‌وجو شامل نویسه‌های غیرمجاز است.',
            'groups.string' => 'فهرست گروه‌ها نامعتبر است.',
            'groups.max' => 'فهرست گروه‌ها نامعتبر است.',
        ]);

        if ($validator->fails()) {
            return $this->respond(['message' => 'ورودی نامعتبر است.', 'errors' => $validator->errors()->toArray()], 422);
        }

        $query = $search->buildQuery((string) $input['q']);
        $length = mb_strlen($query->normalized, 'UTF-8');

        if ($length < (int) config('global_search.query.min_length', 2)) {
            return $this->invalidQuery('متن جست‌وجو کوتاه است.');
        }
        if ($length > (int) config('global_search.query.max_length', 100)) {
            return $this->invalidQuery('متن جست‌وجو بیش از حد طولانی است.');
        }
        if (count($query->tokens) > (int) config('global_search.query.max_tokens', 6)) {
            return $this->invalidQuery('تعداد کلمه‌های جست‌وجو زیاد است.');
        }

        $groups = null;
        $groupsParam = trim((string) ($input['groups'] ?? ''));
        if ($groupsParam !== '') {
            $groups = array_values(array_unique(array_filter(array_map('trim', explode(',', $groupsParam)), fn ($g) => $g !== '')));
            if (array_diff($groups, $search->knownGroupKeys()) !== []) {
                return $this->respond(['message' => 'ورودی نامعتبر است.', 'errors' => ['groups' => ['گروه ناشناخته است.']]], 422);
            }
        }

        $access = $resolver->resolve($request->user());
        if (! $access->isActive) {
            return $this->respond(['message' => 'حساب کاربری غیرفعال است.'], 403);
        }

        $result = $search->search($access, $query, $groups);

        return $this->respond([
            'query' => $query->raw,
            'normalized' => $query->normalized,
            'groups' => $result['groups'],
            'complete' => $result['complete'],
        ], 200);
    }

    private function invalidQuery(string $message): JsonResponse
    {
        return $this->respond(['message' => 'ورودی نامعتبر است.', 'errors' => ['q' => [$message]]], 422);
    }

    private function respond(array $payload, int $status): JsonResponse
    {
        return response()
            // INVALID_UTF8_SUBSTITUTE: لایهٔ دفاعیِ دوم — ساختِ پاسخ هرگز به خطای 500 تبدیل نمی‌شود
            ->json($payload, $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Vary' => 'Cookie',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
