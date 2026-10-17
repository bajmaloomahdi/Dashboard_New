import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react';
import { Input, Modal, Spin, Typography } from 'antd';
import type { InputRef } from 'antd';
import {
    ApartmentOutlined,
    BarChartOutlined,
    CheckSquareOutlined,
    FileTextOutlined,
    MailOutlined,
    ProjectOutlined,
    SearchOutlined,
    TeamOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { Menu } from '../../Types';
import { toBool } from '../../Utils/bool';
import { findMatchRange, normalizeForSearch, normalizeWithMap, tokenize, type NormalizedText } from '../../Utils/persianSearch';
import { useServerSearch, type ServerGroup, type ServerItem } from './useServerSearch';

const { Text } = Typography;

const MAX_RESULTS = 8;

type Group = 'page' | 'report';

interface SearchItem {
    /** شناسهٔ رشته‌ای (مثلِ Sidebar، که کلیدِ Map را خامِ MenuID می‌گیرد) */
    key: string;
    /** جایگاه در فهرستِ menus — آخرین معیارِ ترتیب، بدونِ نیاز به عددی‌بودنِ شناسه */
    position: number;
    title: string;
    url: string;
    group: Group;
    /** مسیرِ والدها، مثلاً «مدیریت › کاربران» */
    path: string;
    titleNorm: NormalizedText;
    haystack: string;
    level: number;
    sortOrder: number;
}

interface MenuSearchPaletteProps {
    open: boolean;
    onClose: () => void;
    /** با هر بازکردن (کلیک یا Ctrl+K/⌘K) افزایش می‌یابد تا کادر دوباره فوکوس و انتخاب شود */
    focusToken: number;
    isMobile?: boolean;
}

/** شناسهٔ خالی/null/undefined → null؛ در غیرِ این صورت نسخهٔ رشته‌ای (هم‌ارزِ مقایسهٔ Sidebar) */
const idKey = (v: unknown): string | null => {
    if (v === null || v === undefined) return null;
    const s = String(v).trim();
    return s === '' ? null : s;
};

/** مقدارِ خامِ یک فیلد به‌صورتِ رشته؛ null/undefined → '' (دادهٔ SP ممکن است با نوعِ TypeScript فرق کند) */
const rawText = (v: unknown): string => (v === null || v === undefined ? '' : String(v));

/** فقط مسیرِ داخلیِ نسبی: با «/» شروع شود و کاراکترِ دوم «/» یا «\» نباشد (جلوگیری از //host و /\host) */
const isInternalPath = (url: string): boolean => /^\/(?![\/\\])/.test(url);

const isLogout = (url: string): boolean => url.split(/[?#]/)[0].replace(/\/+$/, '').toLowerCase() === '/logout';

/**
 * گروهِ «گزارش»: ردیف‌هایِ گزارشِ sp_GetUserMenus (IsReport=1 / MenuKind='REPORT_ITEM')
 * و منویِ نوعِ REPORT («گزارش (REPORT)» در MenuFormModal.tsx:317). بقیه «صفحه».
 */
const groupOf = (m: Menu, kind: string): Group =>
    toBool(m.IsReport) || kind === 'REPORT_ITEM' || kind === 'REPORT' ? 'report' : 'page';

/**
 * فقط مواردی که Sidebar هم قابلِ‌بازشدن نشان می‌دهد (Sidebar.tsx:53-56 و 111؛ menuHelpers.ts:20-33):
 * بدونِ FOLDER/TAB، بدونِ مواردِ دارایِ زیرمجموعه، بدونِ Url، بدونِ /logout، فقط مسیرِ داخلی،
 * و فقط مواردی که زنجیرهٔ والدهایشان به ریشه (ParentID === null، دقیقاً مثلِ buildMenuTree) می‌رسد.
 */
function buildSearchItems(menus: Menu[]): SearchItem[] {
    const byId = new Map<string, { menu: Menu; position: number }>();
    menus.forEach((m, position) => {
        const key = idKey(m.MenuID);
        if (key !== null && !byId.has(key)) byId.set(key, { menu: m, position });
    });

    const parentKeys = new Set<string>();
    byId.forEach(({ menu }) => {
        if (menu.ParentID === null) return;
        const pk = idKey(menu.ParentID);
        if (pk !== null && byId.has(pk)) parentKeys.add(pk);
    });

    /** عنوان‌هایِ والدها از ریشه تا والدِ مستقیم؛ null اگر به ریشه نرسد (والدِ ناموجود، undefined یا حلقه) */
    const ancestorsOf = (m: Menu): string[] | null => {
        const titles: string[] = [];
        const seen = new Set<string>();
        let cur: Menu = m;
        while (cur.ParentID !== null) {
            const pk = idKey(cur.ParentID); // undefined یا خالی → null → مثلِ Sidebar حذف می‌شود
            if (pk === null || seen.has(pk)) return null;
            seen.add(pk);
            const parent = byId.get(pk);
            if (!parent) return null;
            titles.unshift(rawText(parent.menu.MenuTitle));
            cur = parent.menu;
        }
        return titles;
    };

    const items: SearchItem[] = [];
    byId.forEach(({ menu: m, position }, key) => {
        const kind = rawText(m.MenuKind).trim().toUpperCase();
        const url = rawText(m.Url).trim();
        if (kind === 'FOLDER' || kind === 'TAB') return;
        if (parentKeys.has(key)) return;
        if (!url || isLogout(url) || !isInternalPath(url)) return;

        const ancestors = ancestorsOf(m);
        if (ancestors === null) return;

        const title = rawText(m.MenuTitle);
        if (title.trim() === '') return;
        const path = ancestors.join(' › ');
        const titleNorm = normalizeWithMap(title);
        items.push({
            key,
            position,
            title,
            url,
            group: groupOf(m, kind),
            path,
            titleNorm,
            haystack: `${titleNorm.text} ${normalizeForSearch(path)}`,
            level: Number(m.Level) || 0,
            sortOrder: Number(m.SortOrder) || 0,
        });
    });
    return items;
}

function scoreItem(item: SearchItem, q: string, tokens: string[]): number | null {
    if (!tokens.every((t) => item.haystack.includes(t))) return null;
    const t = item.titleNorm.text;
    if (t === q) return 500;
    if (t.startsWith(q)) return 400;
    if (t.split(' ').some((w) => w.startsWith(q))) return 300;
    if (t.includes(q)) return 200;
    return 100; // فقط در مسیرِ والدها
}

/**
 * حداکثر ۸ نتیجه، دقیقاً به ترتیبِ امتیاز (بدونِ جابه‌جاییِ گروهی)؛ گروه فقط به‌صورتِ برچسب کنارِ هر مورد
 * نمایش داده می‌شود. پس بهترین نتیجه اندیسِ ۰ است و هر نتیجه بالاتر از نتایجِ کم‌امتیازتر قرار می‌گیرد.
 */
function search(items: SearchItem[], query: string): SearchItem[] {
    const q = normalizeForSearch(query);
    if (!q) return [];
    const tokens = tokenize(q);
    return items
        .map((item) => ({ item, score: scoreItem(item, q, tokens) }))
        .filter((s): s is { item: SearchItem; score: number } => s.score !== null)
        .sort(
            (a, b) =>
                b.score - a.score ||
                a.item.title.length - b.item.title.length ||
                a.item.level - b.item.level ||
                a.item.sortOrder - b.item.sortOrder ||
                a.item.position - b.item.position
        )
        .slice(0, MAX_RESULTS)
        .map((s) => s.item);
}

function Highlighted({ item, query }: { item: SearchItem; query: string }) {
    const q = normalizeForSearch(query);
    const range = findMatchRange(item.titleNorm, q) ?? findMatchRange(item.titleNorm, tokenize(q)[0] ?? '');
    if (!range) return <>{item.title}</>;
    const [s, e] = range;
    return (
        <>
            {item.title.slice(0, s)}
            <mark style={{ background: '#EDE9FE', color: '#5B21B6', padding: 0, borderRadius: 2 }}>{item.title.slice(s, e)}</mark>
            {item.title.slice(e)}
        </>
    );
}

const srOnly: CSSProperties = {
    position: 'absolute',
    width: 1,
    height: 1,
    padding: 0,
    margin: -1,
    overflow: 'hidden',
    clip: 'rect(0, 0, 0, 0)',
    whiteSpace: 'nowrap',
    border: 0,
};

/** فاصلهٔ داخلیِ ظرفِ نتایج — منبعِ واحد برایِ استایلِ ظرف و محاسبهٔ scrollOptionIntoList */
const LIST_PADDING = 8;
const optionStyle: CSSProperties = { padding: '8px 10px', borderRadius: 8, cursor: 'pointer' };
const hintStyle: CSSProperties = { display: 'block', padding: '8px 10px', fontSize: 13 };
const tagStyle: CSSProperties = { flexShrink: 0, fontSize: 11, color: '#6b7280', background: '#f3f4f6', borderRadius: 4, padding: '0 6px' };

const GROUP_META: Record<Group, { label: string; icon: ReactNode }> = {
    page: { label: 'صفحه', icon: <FileTextOutlined /> },
    report: { label: 'گزارش', icon: <BarChartOutlined /> },
};

/** آیکونِ نتایجِ سرور بر اساسِ نوعِ مورد (SearchResultItem::type) */
const SERVER_ICON: Record<string, ReactNode> = {
    party: <TeamOutlined />,
    person: <TeamOutlined />,
    brand: <TeamOutlined />,
    address: <TeamOutlined />,
    contact: <TeamOutlined />,
    interaction: <TeamOutlined />,
    message: <MailOutlined />,
    task: <CheckSquareOutlined />,
    project: <ProjectOutlined />,
    process_definition: <ApartmentOutlined />,
    process_instance: <ApartmentOutlined />,
};

/** یک گزینهٔ قابلِ انتخاب در فهرست: نتیجهٔ منو (سمتِ کاربر) یا نتیجهٔ سرور */
type Option = { kind: 'menu'; item: SearchItem } | { kind: 'server'; item: ServerItem; group: ServerGroup };

const groupHeaderStyle: CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '10px 10px 4px',
    fontSize: 12,
    fontWeight: 600,
    color: '#6b7280',
};
const badgeStyle: CSSProperties = { ...tagStyle, color: '#92400e', background: '#fef3c7' };

/**
 * جستجویِ سریعِ منوها رویِ prop فعلیِ `menus` (HandleInertiaRequests::getUserMenus) — بدونِ درخواستِ شبکه.
 * اگر سرور features.globalSearch را روشن اعلام کند، نتایجِ سمتِ سرور (POST /global-search؛ مجوز و محدودیتِ نرخ
 * سمتِ سرور) به‌صورتِ گروه‌بندی‌شده زیرِ نتایجِ منو می‌آیند، با وضعیتِ بارگذاری، نتیجهٔ خالی و خطا.
 * ناوبری مثلِ Sidebar با router.visit انجام می‌شود (فقط مسیرهایِ داخلی).
 */
export default function MenuSearchPalette({ open, onClose, focusToken, isMobile = false }: MenuSearchPaletteProps) {
    const { menus, features } = usePage().props as unknown as { menus?: Menu[]; features?: { globalSearch?: boolean } };
    const items = useMemo(() => buildSearchItems(Array.isArray(menus) ? menus : []), [menus]);
    /** جست‌وجویِ سراسریِ سمتِ سرور فقط وقتی سرور فلگ را روشن اعلام کند (features.globalSearch) */
    const serverEnabled = features?.globalSearch === true;

    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const inputRef = useRef<InputRef>(null);
    /** ظرفِ اسکرول‌شوندهٔ نتایج — فقط همین ظرف اسکرول می‌شود، نه پنجره یا صفحه */
    const listContainerRef = useRef<HTMLDivElement>(null);
    /**
     * آخرین مختصاتِ تأییدشدهٔ ماوس؛ null یعنی «رویدادِ بعدی فقط ثبت شود».
     * با تایپ، صفحه‌کلید و بسته‌شدن null می‌شود تا اسکرول یا تغییرِ چیدمان زیرِ ماوسِ ثابت گزینهٔ فعال را عوض نکند.
     */
    const pointerAnchor = useRef<{ x: number; y: number } | null>(null);
    /** مقدارِ فعلیِ open — تا بازنشانیِ پس از بستن، پنجرهٔ دوباره‌بازشده را پاک نکند */
    const openRef = useRef(open);
    openRef.current = open;
    const baseId = useId();
    const listId = `${baseId}-list`;
    const titleId = `${baseId}-title`;
    const optionId = (idx: number) => `${baseId}-opt-${idx}`;

    const results = useMemo(() => search(items, query), [items, query]);
    const hasQuery = normalizeForSearch(query) !== '';
    const server = useServerSearch(query, open && serverEnabled);
    const serverGroups = server.status === 'done' ? server.groups : [];

    /** فهرستِ یکپارچه برایِ صفحه‌کلید: اول نتایجِ منو، بعد نتایجِ سرور به ترتیبِ گروه‌ها (فقط مسیرِ داخلی) */
    const options = useMemo<Option[]>(() => {
        const list: Option[] = results.map((item) => ({ kind: 'menu', item }));
        for (const group of serverGroups) {
            for (const item of group.items) {
                if (isInternalPath(item.url)) list.push({ kind: 'server', item, group });
            }
        }
        return list;
    }, [results, serverGroups]);
    const activeIndex = options.length ? Math.min(active, options.length - 1) : -1;

    useEffect(() => {
        if (!open) return;
        const t = window.setTimeout(() => inputRef.current?.focus({ cursor: 'all' }), 0);
        return () => window.clearTimeout(t);
    }, [open, focusToken]);

    /**
     * گزینهٔ idx را فقط با تنظیمِ scrollTopِ ظرفِ نتایج به دید می‌آورد؛ Modal و صفحه جابه‌جا نمی‌شوند.
     *
     * وابستگیِ صریح به ساختار: ظرف position: relative دارد و listbox و گزینه‌ها static هستند، پس
     * offsetParentِ هر گزینه خودِ ظرف است و offsetTop مستقیماً در مختصاتِ محتوایِ ظرف (از لبهٔ داخلی،
     * شاملِ padding بالا؛ بدونِ اثرِ transform و اسکرول) است. اگر این ساختار تغییر کند، اسکرول انجام نمی‌شود.
     */
    const scrollOptionIntoList = (idx: number) => {
        const container = listContainerRef.current;
        const el = container?.querySelector<HTMLElement>(`[data-option-index="${idx}"]`);
        if (!container || !el || el.offsetParent !== container) return;

        const top = el.offsetTop - LIST_PADDING; // گزینهٔ اول → ۰ (فاصلهٔ بالا هم دیده می‌شود)
        const bottom = el.offsetTop + el.offsetHeight + LIST_PADDING; // گزینهٔ آخر → scrollHeight
        const viewTop = container.scrollTop;
        const viewHeight = container.clientHeight;

        let target: number;
        if (top < viewTop || bottom - top > viewHeight) target = top; // بالاتر از ناحیه، یا بلندتر از آن → لبهٔ بالا
        else if (bottom > viewTop + viewHeight) target = bottom - viewHeight; // پایین‌تر از ناحیه → لبهٔ پایین
        else return; // کامل دیده می‌شود → بدونِ اسکرول

        const maxScroll = Math.max(0, container.scrollHeight - viewHeight);
        container.scrollTop = Math.min(Math.max(0, Math.round(target)), maxScroll);
    };

    /** بازنشانی پس از پایانِ انیمیشنِ بستن؛ اگر پنجره در این فاصله دوباره باز شده باشد، کاری نمی‌کند */
    const handleAfterOpenChange = (isOpen: boolean) => {
        if (isOpen || openRef.current) return;
        setQuery('');
        setActive(0);
        pointerAnchor.current = null;
    };

    const onQueryChange = (value: string) => {
        setQuery(value);
        setActive(0);
        pointerAnchor.current = null;
        // بهترین نتیجه (اندیسِ ۰) همیشه دیده شود، حتی اگر قبلاً با ↓ پایین رفته بودیم
        if (listContainerRef.current) listContainerRef.current.scrollTop = 0;
    };

    const visit = (url?: string | null) => {
        if (!url || !isInternalPath(url)) return;
        onClose();
        router.visit(url);
    };

    const select = (option?: Option) => visit(option?.item.url);

    const onKeyDown = (e: ReactKeyboardEvent<HTMLInputElement>) => {
        if (e.nativeEvent.isComposing) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!options.length) return;
            const delta = e.key === 'ArrowDown' ? 1 : -1;
            const current = activeIndex < 0 ? 0 : activeIndex;
            const next = (current + delta + options.length) % options.length;
            setActive(next);
            pointerAnchor.current = null;
            // گزینهٔ next در DOMِ همین رندر وجود دارد (handler از آخرین رندرِ ثبت‌شده است)
            scrollOptionIntoList(next);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0) select(options[activeIndex]);
        }
        // Esc را خودِ Modal (keyboard=true) با onCancel می‌بندد
    };

    const searching = server.status === 'loading';
    const noResults = hasQuery && options.length === 0 && !searching && server.status !== 'error';
    const status = !hasQuery
        ? ''
        : searching
          ? 'در حال جست‌وجو'
          : options.length
            ? `${options.length} نتیجه`
            : server.status === 'error'
              ? server.message
              : 'نتیجه‌ای یافت نشد';

    /** یک گزینه (منو یا سرور) با همان رفتارِ ماوس و صفحه‌کلیدِ قبلی */
    const renderOption = (option: Option, idx: number) => {
        const isActive = idx === activeIndex;
        const isMenu = option.kind === 'menu';
        return (
            <div
                key={isMenu ? `m-${option.item.key}-${option.item.position}` : `s-${option.group.key}-${option.item.type}-${option.item.id}`}
                id={optionId(idx)}
                data-option-index={idx}
                role="option"
                aria-selected={isActive}
                onMouseMove={(e) => {
                    const anchor = pointerAnchor.current;
                    // اولین رویداد پس از تایپ/صفحه‌کلید/بازشدن فقط ثبت می‌شود؛
                    // رویدادِ ساختگیِ پس از اسکرول یا تغییرِ چیدمان همان مختصات را دارد و نادیده گرفته می‌شود
                    if (anchor === null || (anchor.x === e.clientX && anchor.y === e.clientY)) {
                        pointerAnchor.current = { x: e.clientX, y: e.clientY };
                        return;
                    }
                    pointerAnchor.current = { x: e.clientX, y: e.clientY };
                    if (idx !== activeIndex) setActive(idx);
                }}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => select(option)}
                style={{
                    ...optionStyle,
                    background: isActive ? '#EEF2FF' : 'transparent',
                    // نوارِ ۳ پیکسلی در لبهٔ شروع (راست در RTL) برایِ دیده‌شدنِ گزینهٔ فعال
                    boxShadow: isActive ? 'inset -3px 0 0 #667eea' : 'none',
                }}
            >
                {isMenu ? (
                    <>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span aria-hidden style={{ color: '#6b7280' }}>{GROUP_META[option.item.group].icon}</span>
                            <span style={{ fontSize: 14, color: '#1f2937', flex: 1, minWidth: 0 }}>
                                <Highlighted item={option.item} query={query} />
                            </span>
                            <span style={tagStyle}>{GROUP_META[option.item.group].label}</span>
                        </div>
                        {option.item.path ? (
                            <div style={{ fontSize: 12, color: '#6b7280', paddingInlineStart: 22 }}>{option.item.path}</div>
                        ) : null}
                    </>
                ) : (
                    <>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span aria-hidden style={{ color: '#6b7280' }}>{SERVER_ICON[option.item.type] ?? <SearchOutlined />}</span>
                            <span style={{ fontSize: 14, color: '#1f2937', flex: 1, minWidth: 0 }}>{option.item.title}</span>
                            {option.item.badges.map((b) => (
                                <span key={b} style={badgeStyle}>
                                    {b}
                                </span>
                            ))}
                        </div>
                        {option.item.subtitle ? (
                            <div style={{ fontSize: 12, color: '#6b7280', paddingInlineStart: 22 }}>{option.item.subtitle}</div>
                        ) : null}
                    </>
                )}
            </div>
        );
    };

    const menuCount = results.length;

    return (
        <Modal
            open={open}
            onCancel={onClose}
            footer={null}
            closable={false}
            destroyOnHidden
            afterOpenChange={handleAfterOpenChange}
            // عنوانِ پنهان: rc-dialog آن را با aria-labelledby به dialog وصل می‌کند (نامِ دسترس‌پذیرِ Modal)
            title={<span id={titleId} style={srOnly}>جستجوی ۳۶۰ درجه</span>}
            width={isMobile ? 'calc(100vw - 16px)' : serverEnabled ? 640 : 560}
            style={{ top: isMobile ? 8 : 80 }}
            styles={{ header: { margin: 0, padding: 0, minHeight: 0 }, body: { padding: 0 } }}
            focusable={{ focusTriggerAfterClose: true }}
        >
            <div dir="rtl">
                <Input
                    ref={inputRef}
                    size="large"
                    variant="borderless"
                    prefix={<SearchOutlined aria-hidden />}
                    suffix={searching ? <Spin size="small" /> : undefined}
                    placeholder={serverEnabled ? 'جستجو در صفحه‌ها، طرف‌حساب‌ها، نامه‌ها، پروژه‌ها و فرایندها…' : 'جستجوی صفحه‌ها و گزارش‌ها…'}
                    aria-labelledby={titleId}
                    value={query}
                    onChange={(e) => onQueryChange(e.target.value)}
                    onKeyDown={onKeyDown}
                    dir="auto"
                    autoComplete="off"
                    spellCheck={false}
                    role="combobox"
                    aria-expanded={options.length > 0}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={activeIndex >= 0 ? optionId(activeIndex) : undefined}
                />
                <div
                    ref={listContainerRef}
                    style={{
                        position: 'relative',
                        borderTop: '1px solid #f0f0f0',
                        maxHeight: isMobile ? '60vh' : 360,
                        overflowY: 'auto',
                        padding: LIST_PADDING,
                    }}
                >
                    {!hasQuery ? (
                        <Text type="secondary" style={hintStyle}>
                            {serverEnabled
                                ? 'نام صفحه، طرف‌حساب، شماره یا موضوعِ نامه، کدِ پروژه یا فرایند را تایپ کنید — ↑ ↓ حرکت، Enter بازکردن، Esc بستن'
                                : 'نام صفحه یا گزارش را تایپ کنید — ↑ ↓ حرکت، Enter بازکردن، Esc بستن'}
                        </Text>
                    ) : noResults ? (
                        <Text type="secondary" style={hintStyle}>
                            {serverEnabled ? 'نتیجه‌ای یافت نشد' : 'صفحه‌ای یافت نشد'}
                        </Text>
                    ) : null}

                    {/* listbox همیشه در DOM است تا aria-controls معتبر بماند (الگویِ combobox)؛ وقتی خالی است hidden می‌شود */}
                    <div role="listbox" id={listId} aria-label="نتایج جستجو" hidden={options.length === 0}>
                        {menuCount > 0 && serverGroups.length > 0 ? (
                            <div aria-hidden style={groupHeaderStyle}>
                                <span>صفحه‌ها و گزارش‌ها</span>
                            </div>
                        ) : null}
                        {options.slice(0, menuCount).map((option, idx) => renderOption(option, idx))}

                        {(() => {
                            // گروه‌هایِ سرور، به ترتیبِ پاسخ؛ اندیسِ گزینه‌ها پس از نتایجِ منو ادامه می‌یابد
                            let idx = menuCount;
                            return serverGroups.map((group) => {
                                const groupOptions = options.filter((o) => o.kind === 'server' && o.group === group);
                                if (!groupOptions.length) return null;
                                const start = idx;
                                idx += groupOptions.length;
                                return (
                                    <div key={group.key} role="group" aria-label={group.label}>
                                        <div aria-hidden style={groupHeaderStyle}>
                                            <span>{group.label}</span>
                                            {group.hasMore && group.moreUrl && isInternalPath(group.moreUrl) ? (
                                                <a
                                                    href={group.moreUrl}
                                                    tabIndex={-1}
                                                    style={{ fontWeight: 400, fontSize: 12 }}
                                                    onMouseDown={(e) => e.preventDefault()}
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        visit(group.moreUrl);
                                                    }}
                                                >
                                                    نتایج بیشتر
                                                </a>
                                            ) : null}
                                        </div>
                                        {groupOptions.map((option, i) => renderOption(option, start + i))}
                                    </div>
                                );
                            });
                        })()}
                    </div>

                    {serverEnabled && hasQuery && searching ? (
                        <Text type="secondary" style={hintStyle}>
                            <Spin size="small" style={{ marginInlineEnd: 8 }} />
                            در حال جست‌وجو در اطلاعات…
                        </Text>
                    ) : null}
                    {server.status === 'error' ? (
                        <Text type="danger" style={hintStyle} role="alert">
                            {server.message}
                        </Text>
                    ) : null}
                    {server.status === 'done' && !server.complete ? (
                        <Text type="warning" style={hintStyle}>
                            برخی بخش‌ها به‌موقع پاسخ ندادند؛ نتایج ممکن است کامل نباشد.
                        </Text>
                    ) : null}
                </div>
                <div aria-live="polite" style={srOnly}>
                    {status}
                </div>
            </div>
        </Modal>
    );
}
