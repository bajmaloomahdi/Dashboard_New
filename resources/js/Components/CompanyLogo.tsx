import { useState } from 'react';

/* ------------------------------------------------------------------ */
/* کامپوننت مشترک لوگوی شرکت                                          */
/* ─────────────────────────────────────────────────────────────────── */
/* باکس لوگو به‌صورت خودکار با نسبت ابعاد تصویر تطبیق پیدا می‌کند:      */
/*   مربع  ⇒ باکس مربع                                                 */
/*   پهن   ⇒ باکس مستطیل افقی                                          */
/*   کشیده ⇒ باکس مستطیل عمودی                                         */
/* ------------------------------------------------------------------ */

export type LogoVariant = 'login' | 'welcome' | 'header';

interface SizeConfig {
    /** حداکثر عرض باکس (لوگوهای پهن) */
    maxWidth: number;
    /** حداکثر ارتفاع باکس (لوگوهای کشیده عمودی) */
    maxHeight: number;
    /** اندازه باکس مربعی */
    square: number;
    /** فاصله داخلی */
    padding: number;
    /** شعاع گوشه‌ها */
    radius: number;
    /** سایه */
    shadow: string;
    shadowHover: string;
    /** اندازه فونت حالت بدون لوگو */
    fallbackFontSize: number;
}

const PRESETS: Record<LogoVariant, SizeConfig> = {
    // صفحه ورود — بزرگ
    login: {
        maxWidth: 340,
        maxHeight: 260,
        square: 220,
        padding: 18,
        radius: 28,
        shadow: '0 18px 45px rgba(0,0,0,0.25)',
        shadowHover: '0 24px 55px rgba(0,0,0,0.30)',
        fallbackFontSize: 90,
    },
    // کارت خوشامدگویی داشبورد — متوسط
    welcome: {
        maxWidth: 170,
        maxHeight: 110,
        square: 96,
        padding: 8,
        radius: 18,
        shadow: '0 8px 24px rgba(0,0,0,0.22)',
        shadowHover: '0 14px 32px rgba(0,0,0,0.28)',
        fallbackFontSize: 44,
    },
    // هدر بالای صفحه — کوچک
    header: {
        maxWidth: 120,
        maxHeight: 52,
        square: 44,
        padding: 5,
        radius: 12,
        shadow: '0 2px 8px rgba(0,0,0,0.12)',
        shadowHover: '0 6px 14px rgba(0,0,0,0.18)',
        fallbackFontSize: 22,
    },
};

interface CompanyLogoProps {
    /** آیا شرکت لوگو دارد؟ معمولاً company?.LogoMimeType */
    hasLogo?: boolean | string | null;
    /** اندازه از پیش تعریف‌شده */
    variant?: LogoVariant;
    /** آدرس لوگو (پیش‌فرض: /company/logo) */
    src?: string;
    /** ایموجی یا محتوای جایگزین وقتی لوگو وجود ندارد */
    fallback?: React.ReactNode;
    /** رنگ پس‌زمینه باکس */
    background?: string;
    /** افکت هاور */
    hoverable?: boolean;
    /** استایل اضافی روی باکس */
    style?: React.CSSProperties;
    /** بازنویسی تنظیمات اندازه */
    config?: Partial<SizeConfig>;
}

export default function CompanyLogo({
    hasLogo,
    variant = 'welcome',
    src,
    fallback = '🏢',
    background = 'rgba(255,255,255,0.95)',
    hoverable = true,
    style,
    config,
}: CompanyLogoProps) {
    const cfg: SizeConfig = { ...PRESETS[variant], ...config };

    const [box, setBox] = useState<{ width: number; height: number }>({
        width: cfg.square + cfg.padding * 2,
        height: cfg.square + cfg.padding * 2,
    });
    const [ready, setReady] = useState(false);
    const [hover, setHover] = useState(false);
    const [failed, setFailed] = useState(false);

    /** محاسبه ابعاد باکس بر اساس نسبت ابعاد واقعی تصویر */
    const handleLoad = (e: React.SyntheticEvent<HTMLImageElement>) => {
        const img = e.currentTarget;
        const w = img.naturalWidth || 1;
        const h = img.naturalHeight || 1;
        const ratio = w / h;

        const minSide = Math.round(cfg.square * 0.55);
        let boxW: number;
        let boxH: number;

        if (ratio > 1.15) {
            boxW = cfg.maxWidth;
            boxH = Math.max(minSide, Math.round(cfg.maxWidth / ratio));
            if (boxH > cfg.maxHeight) {
                boxH = cfg.maxHeight;
                boxW = Math.round(cfg.maxHeight * ratio);
            }
        } else if (ratio < 0.87) {
            boxH = cfg.maxHeight;
            boxW = Math.max(minSide, Math.round(cfg.maxHeight * ratio));
        } else {
            boxW = cfg.square;
            boxH = cfg.square;
        }

        setBox({ width: boxW + cfg.padding * 2, height: boxH + cfg.padding * 2 });
        setReady(true);
    };

    const showImage = !!hasLogo && !failed;

    const boxStyle: React.CSSProperties = {
        width: showImage ? box.width : cfg.square + cfg.padding * 2,
        height: showImage ? box.height : cfg.square + cfg.padding * 2,
        padding: cfg.padding,
        background,
        borderRadius: cfg.radius,
        boxShadow: hover && hoverable ? cfg.shadowHover : cfg.shadow,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        overflow: 'hidden',
        maxWidth: '100%',
        opacity: showImage ? (ready ? 1 : 0) : 1,
        transform: hover && hoverable ? 'translateY(-3px) scale(1.02)' : 'none',
        transition:
            'width .35s cubic-bezier(.4,0,.2,1), height .35s cubic-bezier(.4,0,.2,1), transform .3s ease, box-shadow .3s ease, opacity .3s ease',
        fontSize: showImage ? undefined : cfg.fallbackFontSize,
        lineHeight: 1,
        ...style,
    };

    return (
        <div
            style={boxStyle}
            onMouseEnter={() => hoverable && setHover(true)}
            onMouseLeave={() => hoverable && setHover(false)}
        >
            {showImage ? (
                <img
                    src={src || `/company/logo?t=${Date.now()}`}
                    alt="لوگوی شرکت"
                    onLoad={handleLoad}
                    onError={() => setFailed(true)}
                    style={{
                        width: '100%',
                        height: '100%',
                        objectFit: 'contain',
                        display: 'block',
                    }}
                />
            ) : (
                fallback
            )}
        </div>
    );
}
