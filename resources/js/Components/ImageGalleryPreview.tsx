import { Image } from 'antd';

export interface GalleryItem {
    src: string;
    alt?: string;
}

interface ImageGalleryPreviewProps {
    items: GalleryItem[];
    /** اندیسِ تصویرِ بازشده؛ null = بسته */
    current: number | null;
    onChange: (current: number | null) => void;
}

/**
 * نمایشگرِ گالریِ تصویرِ اصلی (بزرگ‌نمایی، چرخش، قبلی/بعدی) بدونِ رندرِ بندانگشتی —
 * برایِ جاهایی که بندانگشتیِ خودشان را دارند (لوگویِ برند، لوگو و فاوآیکونِ شرکت).
 */
export default function ImageGalleryPreview({ items, current, onChange }: ImageGalleryPreviewProps) {
    if (items.length === 0) return null;

    return (
        <Image.PreviewGroup
            items={items.map((i) => ({ src: i.src, alt: i.alt }))}
            preview={{
                open: current !== null,
                current: current ?? 0,
                onOpenChange: (open) => {
                    if (!open) onChange(null);
                },
                onChange: (next) => onChange(next),
            }}
        />
    );
}
