<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CompanyController extends Controller
{
    /**
     * نمایش صفحه تنظیمات شرکت
     */
    public function index()
    {
        $company = DB::select('EXEC sp_GetCompany');
        $company = $company[0] ?? null;

        return Inertia::render('Company/Index', [
            'company' => $company ? (array) $company : null,
        ]);
    }

    /**
     * ذخیره اطلاعات شرکت
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'Code' => 'required|string|max:50',
            'Name' => 'required|string|max:200',
            'Description' => 'nullable|string|max:1000',
            'SiteTitle' => 'nullable|string|max:100',
            'Logo' => 'nullable|image|max:2048',        // حداکثر 2MB
            'Favicon' => 'nullable|image|max:512',      // حداکثر 512KB
        ]);

        // ---------- تبدیل تصاویر به Base64 ----------
        $logoBase64 = null;
        $logoMime = null;
        if ($request->hasFile('Logo')) {
            $file = $request->file('Logo');
            $logoBase64 = base64_encode(file_get_contents($file->getRealPath()));
            $logoMime = $file->getMimeType();
        }

        $faviconBase64 = null;
        $faviconMime = null;
        if ($request->hasFile('Favicon')) {
            $file = $request->file('Favicon');
            $faviconBase64 = base64_encode(file_get_contents($file->getRealPath()));
            $faviconMime = $file->getMimeType();
        }

        $result = DB::select(
            'EXEC sp_SaveCompany
                @Code = ?, @Name = ?, @Description = ?, @SiteTitle = ?,
                @LogoBase64 = ?, @LogoMimeType = ?,
                @FaviconBase64 = ?, @FaviconMimeType = ?,
                @UserID = ?',
            [
                $validated['Code'],
                $validated['Name'],
                $validated['Description'] ?? null,
                $validated['SiteTitle'] ?? null,
                $logoBase64,
                $logoMime,
                $faviconBase64,
                $faviconMime,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'Name' => $response['Message'] ?? 'خطا در ذخیره اطلاعات شرکت',
            ]);
        }

        return redirect()->route('company.index')
            ->with('success', $response['Message']);
    }

    /**
     * مانیفست PWA (برای نام و آیکون هنگام افزودن به صفحه اصلی موبایل)
     */
    public function manifest()
    {
        $result = DB::select('EXEC sp_GetCompany');
        $company = $result[0] ?? null;

        $title = $company->SiteTitle ?: ($company->Name ?? 'پنل مدیریت');

        return response()->json([
            'id' => '/',
            'name' => $title,
            'short_name' => $title,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'dir' => 'rtl',
            'lang' => 'fa',
            'background_color' => '#ffffff',
            'theme_color' => '#667eea',
            // آیکون‌هایِ PNGِ مربعِ واقعی (برایِ نصب و Web Push در اندروید/iPhone) — از فاوآیکون/لوگویِ همان شرکت ساخته می‌شوند
            'icons' => [
                ['src' => '/company/pwa-icon/192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/company/pwa-icon/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/company/pwa-icon/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * آیکونِ PNGِ مربع با اندازهٔ دقیق (۱۸۰ برایِ iPhone، ۱۹۲ و ۵۱۲ برایِ مانیفست) از فاوآیکون یا لوگویِ شرکت؛
     * زمینهٔ سفید و ۱۰٪ حاشیه (ناحیهٔ امنِ آیکونِ maskable). اگر هیچ تصویرِ قابلِ‌خواندنی نبود، یک آیکونِ ساده ساخته می‌شود.
     */
    public function pwaIcon(int $size)
    {
        abort_unless(in_array($size, [180, 192, 512], true), 404);

        $source = null;
        foreach (['sp_GetCompanyFavicon' => 'Favicon', 'sp_GetCompanyLogo' => 'Logo'] as $sp => $column) {
            try {
                $row = DB::select("EXEC {$sp}")[0] ?? null;
                if ($row && ! empty($row->{$column})) {
                    $img = @imagecreatefromstring($row->{$column});
                    if ($img !== false) {
                        $source = $img;
                        break;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        if ($source) {
            $sw = imagesx($source);
            $sh = imagesy($source);
            $box = (int) round($size * 0.8);
            $scale = min($box / max(1, $sw), $box / max(1, $sh));
            $w = max(1, (int) round($sw * $scale));
            $h = max(1, (int) round($sh * $scale));
            imagecopyresampled($canvas, $source, (int) (($size - $w) / 2), (int) (($size - $h) / 2), 0, 0, $w, $h, $sw, $sh);
            imagedestroy($source);
        } else {
            $c = imagecolorallocate($canvas, 102, 126, 234);
            $m = (int) round($size * 0.15);
            imagefilledrectangle($canvas, $m, $m, $size - $m, $size - $m, $c);
        }

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return response($png)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * نمایش لوگو (با کش مرورگر برای سرعت بالا و بدون درگیر کردن سشن)
     */
    public function logo()
    {
        $result = DB::select('EXEC sp_GetCompanyLogo');
        $row = $result[0] ?? null;

        if (!$row || !$row->Logo) {
            abort(404);
        }

        return response($row->Logo)
            ->header('Content-Type', $row->LogoMimeType ?? 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * نمایش فاوآیکون
     */
    public function favicon()
    {
        $result = DB::select('EXEC sp_GetCompanyFavicon');
        $row = $result[0] ?? null;

        if (!$row || !$row->Favicon) {
            abort(404);
        }

        return response($row->Favicon)
            ->header('Content-Type', $row->FaviconMimeType ?? 'image/x-icon')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}