<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ $siteTitle ?? 'پنل مدیریت' }}</title>

    <!-- فاوآیکون پویای شرکت از دیتابیس (بالای تب مرورگر) -->
    <link rel="icon" href="/company/favicon">
    <link rel="shortcut icon" href="/company/favicon">

    <!-- نام و آیکون هنگام افزودن به صفحه اصلی موبایل (PWA) -->
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/company/favicon">
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