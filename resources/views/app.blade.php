<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        // iPhone/iPad: Safari با لمسِ فیلدی که فونتش کمتر از ۱۶px است صفحه را خودکار بزرگ می‌کند و برنمی‌گرداند.
        // maximum-scale=1 فقط همین بزرگ‌نماییِ خودکار را می‌گیرد (iOS زومِ دستی با دو انگشت را همچنان اجازه می‌دهد)؛ اندروید دست نمی‌خورد.
        (function () {
            var ua = navigator.userAgent || '';
            var isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (!isIOS) return;
            var m = document.querySelector('meta[name="viewport"]');
            if (m) m.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1');
        })();
    </script>
    <title inertia>{{ $siteTitle ?? 'پنل مدیریت' }}</title>

    <!-- فاوآیکون پویای شرکت از دیتابیس (بالای تب مرورگر) -->
    <link rel="icon" href="/company/favicon">
    <link rel="shortcut icon" href="/company/favicon">

    <!-- نام و آیکون هنگام افزودن به صفحه اصلی موبایل (PWA) -->
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/company/pwa-icon/180">
    <meta name="apple-mobile-web-app-title" content="{{ $siteTitle ?? 'پنل مدیریت' }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#667eea">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-slate-100 text-slate-800">
    @inertia
</body>
</html>