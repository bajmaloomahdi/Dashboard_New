import { useEffect, useRef, useState } from 'react';
import { Alert, Button, InputNumber, Space, Typography } from 'antd';
import { AimOutlined, DeleteOutlined } from '@ant-design/icons';
import LocationSearchAutocomplete, { LocationSearchResult } from './LocationSearchAutocomplete';

const { Text } = Typography;

/** مرکزِ پیش‌فرضِ نقشه وقتی آدرس مختصات ندارد — جغرافیایِ CRM مختصاتِ شهر ندارد و سرویسِ جست‌وجو هم استفاده نمی‌شود. */
const TEHRAN: [number, number] = [51.389, 35.6892]; // [lng, lat]
const DEFAULT_ZOOM = 11;
const POINT_ZOOM = 16;
const STYLE_URL = 'https://static.neshan.org/sdk/maplibre/styles/light.json';

interface AddressLocationPickerProps {
    /** Map Keyِ نقشهٔ وبِ نشان — از .env، از طریقِ Propِ صفحه؛ خالی = فقط ورودِ دستی */
    apiKey: string | null | undefined;
    latitude: number | null;
    longitude: number | null;
    onChange: (latitude: number | null, longitude: number | null) => void;
    /** جست‌وجویِ محل («تبدیل آدرس به نقطه» نشان از طریقِ proxyِ سرور) — فقط وقتی کلیدِ Service تنظیم شده باشد */
    searchEnabled?: boolean;
    /** نامِ شهرِ انتخاب‌شده در فرمِ CRM — همراهِ جست‌وجو ارسال می‌شود تا نتایج دقیق‌تر باشند */
    cityName?: string | null;
}

const round6 = (v: number) => Math.round(v * 1e6) / 1e6;

/**
 * انتخابِ موقعیتِ آدرس رویِ نقشهٔ وبِ نشان (MapLibre SDK): کلیک رویِ نقشه یا کشیدنِ Marker مختصات را
 * تنظیم می‌کند؛ ورودِ دستی و «حذفِ موقعیت» هم هست. نقشه فقط Map Key لازم دارد؛ جست‌وجویِ اختیاری از
 * «تبدیل آدرس به نقطه» نشان (Geocoding) از طریقِ proxyِ سرور استفاده می‌کند — نه Search/POI/Reverse Geocoding.
 * SDK (~۱.۳ مگابایت) فقط هنگامِ نمایشِ این کامپوننت بارگذاری می‌شود.
 */
export default function AddressLocationPicker({ apiKey, latitude, longitude, onChange, searchEnabled = false, cityName = null }: AddressLocationPickerProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const markerRef = useRef<any>(null);
    const sdkRef = useRef<any>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const [mapError, setMapError] = useState<string | null>(null);
    const [loading, setLoading] = useState(!!apiKey);

    const hasPoint = latitude !== null && longitude !== null;

    /** انتخابِ نتیجهٔ جست‌وجو: Marker و مرکزِ نقشه به همان نقطه، و مختصاتِ فرم پر می‌شود؛ Marker همچنان قابلِ کشیدن است */
    const selectResult = (r: LocationSearchResult) => {
        placeMarker(r.longitude, r.latitude);
        mapRef.current?.flyTo({ center: [r.longitude, r.latitude], zoom: POINT_ZOOM });
        onChange(r.latitude, r.longitude);
    };

    /** Marker را رویِ [lng, lat] می‌گذارد (در صورتِ نبود می‌سازد)؛ کشیدنِ آن مختصات را به والد می‌دهد. */
    const placeMarker = (lng: number, lat: number) => {
        const sdk = sdkRef.current;
        const map = mapRef.current;
        if (!sdk || !map) return;
        if (!markerRef.current) {
            markerRef.current = new sdk.Marker({ draggable: true, color: '#667eea' }).setLngLat([lng, lat]).addTo(map);
            markerRef.current.on('dragend', () => {
                const p = markerRef.current.getLngLat();
                onChangeRef.current(round6(p.lat), round6(p.lng));
            });
        } else {
            markerRef.current.setLngLat([lng, lat]);
        }
    };

    // ساختِ نقشه یک‌بار؛ مختصاتِ فعلی (ویرایش) مرکز و Marker را تعیین می‌کند
    useEffect(() => {
        if (!apiKey || !containerRef.current) return;
        let cancelled = false;

        (async () => {
            try {
                const [{ default: sdk }] = await Promise.all([
                    import('@neshan-maps-platform/maplibre-sdk'),
                    import('@neshan-maps-platform/maplibre-sdk/style.css'),
                ]);
                if (cancelled || !containerRef.current) return;
                sdkRef.current = sdk;
                const map = new sdk.Map({
                    container: containerRef.current,
                    style: STYLE_URL,
                    center: hasPoint ? [longitude!, latitude!] : TEHRAN,
                    zoom: hasPoint ? POINT_ZOOM : DEFAULT_ZOOM,
                    apiKey,
                });
                mapRef.current = map;
                map.on('click', (e: any) => {
                    const lat = round6(e.lngLat.lat);
                    const lng = round6(e.lngLat.lng);
                    placeMarker(lng, lat);
                    onChangeRef.current(lat, lng);
                });
                map.on('error', (e: any) => {
                    if (e?.error?.status === 401 || e?.error?.status === 403) setMapError('کلیدِ نقشهٔ نشان معتبر نیست یا برایِ این دامنه مجاز نیست.');
                });
                map.on('load', () => !cancelled && setLoading(false));
                if (hasPoint) placeMarker(longitude!, latitude!);
            } catch (err: any) {
                if (!cancelled) {
                    setLoading(false);
                    setMapError(`بارگذاریِ نقشه ممکن نشد${err?.message ? `: ${err.message}` : ''}`);
                }
            }
        })();

        return () => {
            cancelled = true;
            markerRef.current = null;
            mapRef.current?.remove();
            mapRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [apiKey]);

    // هم‌گام‌سازی با تغییرِ بیرونی (ورودِ دستی / حذفِ موقعیت)
    useEffect(() => {
        if (!mapRef.current) return;
        if (hasPoint) {
            const cur = markerRef.current?.getLngLat();
            if (!cur || round6(cur.lat) !== latitude || round6(cur.lng) !== longitude) {
                placeMarker(longitude!, latitude!);
                mapRef.current.easeTo({ center: [longitude!, latitude!] });
            }
        } else if (markerRef.current) {
            markerRef.current.remove();
            markerRef.current = null;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [latitude, longitude]);

    return (
        <div>
            {searchEnabled ? (
                <div style={{ marginBottom: 8 }}>
                    <LocationSearchAutocomplete cityName={cityName} onSelect={selectResult} />
                </div>
            ) : null}

            {!apiKey ? (
                <Alert type="warning" showIcon style={{ marginBottom: 8, borderRadius: 8 }} message="کلیدِ نقشهٔ نشان (NESHAN_MAP_KEY) تنظیم نشده است؛ مختصات را می‌توانید دستی وارد کنید." />
            ) : (
                <>
                    {mapError ? <Alert type="error" showIcon style={{ marginBottom: 8, borderRadius: 8 }} message={mapError} /> : null}
                    <div style={{ position: 'relative', height: 320, borderRadius: 10, overflow: 'hidden', border: '1px solid #e5e7eb' }}>
                        <div ref={containerRef} style={{ position: 'absolute', inset: 0 }} />
                        {loading ? (
                            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f9fafb' }}>
                                <Text type="secondary">در حالِ بارگذاریِ نقشه…</Text>
                            </div>
                        ) : null}
                    </div>
                    <Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 4 }}>
                        برایِ تعیینِ محل رویِ نقشه کلیک کنید یا نشانگر را بکشید.
                    </Text>
                </>
            )}

            <Space wrap align="end" style={{ marginTop: 8 }}>
                <div>
                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>عرضِ جغرافیایی (Latitude)</Text>
                    <InputNumber
                        dir="ltr"
                        style={{ width: 160 }}
                        min={-90}
                        max={90}
                        precision={6}
                        step={0.000001}
                        value={latitude ?? undefined}
                        onChange={(v) => onChange(v === null || v === undefined ? null : Number(v), longitude)}
                    />
                </div>
                <div>
                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>طولِ جغرافیایی (Longitude)</Text>
                    <InputNumber
                        dir="ltr"
                        style={{ width: 160 }}
                        min={-180}
                        max={180}
                        precision={6}
                        step={0.000001}
                        value={longitude ?? undefined}
                        onChange={(v) => onChange(latitude, v === null || v === undefined ? null : Number(v))}
                    />
                </div>
                {hasPoint && mapRef.current ? (
                    <Button icon={<AimOutlined />} onClick={() => mapRef.current?.easeTo({ center: [longitude!, latitude!], zoom: POINT_ZOOM })}>
                        نمایشِ محل
                    </Button>
                ) : null}
                <Button danger icon={<DeleteOutlined />} disabled={latitude === null && longitude === null} onClick={() => onChange(null, null)}>
                    حذفِ موقعیت
                </Button>
            </Space>
        </div>
    );
}
