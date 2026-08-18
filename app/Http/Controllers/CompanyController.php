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
                @Code = ?, @Name = ?, @Description = ?,
                @LogoBase64 = ?, @LogoMimeType = ?,
                @FaviconBase64 = ?, @FaviconMimeType = ?,
                @UserID = ?',
            [
                $validated['Code'],
                $validated['Name'],
                $validated['Description'] ?? null,
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
     * نمایش لوگو (برای استفاده در مرورگر)
     */
    public function logo()
    {
        $result = DB::select('EXEC sp_GetCompanyLogo');
        $row = $result[0] ?? null;

        if (!$row || !$row->Logo) {
            abort(404);
        }

        return response($row->Logo)
            ->header('Content-Type', $row->LogoMimeType ?? 'image/png');
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
            ->header('Content-Type', $row->FaviconMimeType ?? 'image/x-icon');
    }
}