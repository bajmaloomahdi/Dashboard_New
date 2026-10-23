import { useEffect, useState } from 'react';
import { Button, Checkbox, Divider, Select, Space, Switch, Tag, Typography } from 'antd';
import { LeftOutlined, RightOutlined, ShareAltOutlined } from '@ant-design/icons';
import { WEEKDAY_LABELS_FA, addJalaliMonths, buildMonthGrid, jalaliMonthTitle } from '../../Utils/jalaliCalendar';
import WeekdayName from './WeekdayName';
import { toEnglishDigits } from '../../Utils/jalali';
import { THEME } from '../../theme';
import {
    EMPTY_FILTERS,
    activeFilterCount,
    dayKey,
    sharedKey,
    unitKey,
    type CalendarSources,
    sameDay,
    type CalendarFilterState,
    type EventStatus,
    type Relation,
    type ResponseStatus,
    type WorkWeekSettings,
} from './calendarUtils';

const { Text } = Typography;

interface UserOption {
    UserID: number;
    FullName: string;
}

/** ماهِ شمسیِ کوچک برایِ پرش به تاریخ؛ روزهایِ دارایِ رویداد (در بازهٔ بارگذاری‌شده) نقطه دارند */
function MiniMonth({ selected, onPick, eventDays }: { selected: Date; onPick: (d: Date) => void; eventDays: Set<string> }) {
    const [month, setMonth] = useState<Date>(selected);
    useEffect(() => setMonth(selected), [selected]);
    const cells = buildMonthGrid(month);
    const today = new Date();

    return (
        <div className="cal-mini">
            <div className="cal-mini-head">
                <Button type="text" size="small" icon={<RightOutlined />} onClick={() => setMonth((m) => addJalaliMonths(m, -1))} />
                <span>{toEnglishDigits(jalaliMonthTitle(month))}</span>
                <Button type="text" size="small" icon={<LeftOutlined />} onClick={() => setMonth((m) => addJalaliMonths(m, 1))} />
            </div>
            <div className="cal-mini-grid">
                {WEEKDAY_LABELS_FA.map((w, i) => (
                    <div key={w} className="cal-mini-wd">
                        <WeekdayName index={i} />
                    </div>
                ))}
                {cells.map((c) => (
                    <button
                        type="button"
                        key={c.date.toISOString()}
                        className={`cal-mini-day${c.isCurrentMonth ? '' : ' is-outside'}${c.isToday ? ' is-today' : ''}${sameDay(c.date, selected) ? ' is-selected' : ''}`}
                        onClick={() => onPick(c.date)}
                        title={sameDay(c.date, today) ? 'امروز' : undefined}
                    >
                        {toEnglishDigits(String(c.jalaliDay))}
                        {eventDays.has(dayKey(c.date)) && <span className="cal-mini-dot" />}
                    </button>
                ))}
            </div>
            <style>{`
                .cal-mini-head { display: flex; align-items: center; justify-content: space-between; font-weight: 700; font-size: 13px; margin-bottom: 4px; }
                .cal-mini-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
                .cal-mini-wd { text-align: center; font-size: 9.5px; line-height: 1.2; color: ${THEME.textSecondary}; padding: 2px 0; min-width: 0; display: flex; align-items: center; justify-content: center; }
                .cal-mini-day { position: relative; border: none; background: transparent; border-radius: 6px; height: 28px; font-size: 12px; cursor: pointer; color: ${THEME.textPrimary}; font-family: inherit; }
                .cal-mini-day:hover { background: ${THEME.bgHover}; }
                .cal-mini-day.is-outside { color: ${THEME.textLight}; }
                .cal-mini-day.is-today { color: ${THEME.primary}; font-weight: 700; }
                .cal-mini-day.is-selected { background: ${THEME.primary}; color: #fff; }
                .cal-mini-dot { position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: ${THEME.primary}; }
                .cal-mini-day.is-selected .cal-mini-dot { background: #fff; }
            `}</style>
        </div>
    );
}

export default function CalendarSidebar({
    referenceDate,
    onPickDate,
    eventDays,
    viewableUsers,
    canViewOthers,
    viewingUserId,
    currentUserId,
    onChangeUser,
    filters,
    onChangeFilters,
    workWeek,
    onChangeWorkWeek,
    sources,
    selectedSources,
    onToggleSource,
    sourceColors,
    onOpenShare,
}: {
    referenceDate: Date;
    onPickDate: (d: Date) => void;
    eventDays: Set<string>;
    viewableUsers: UserOption[];
    canViewOthers: boolean;
    viewingUserId: number;
    currentUserId: number;
    onChangeUser: (id: number) => void;
    filters: CalendarFilterState;
    onChangeFilters: (f: CalendarFilterState) => void;
    workWeek: WorkWeekSettings;
    onChangeWorkWeek: (w: WorkWeekSettings) => void;
    /** تقویم‌هایِ هم‌زمان: اشتراک‌گذاشته‌شده با من + واحدهایِ من (فقط وقتی تقویمِ خودم را می‌بینم) */
    sources: CalendarSources | null;
    selectedSources: string[];
    onToggleSource: (key: string, checked: boolean) => void;
    sourceColors: Record<string, string>;
    onOpenShare?: () => void;
}) {
    const set = <K extends keyof CalendarFilterState>(key: K, value: CalendarFilterState[K]) => onChangeFilters({ ...filters, [key]: value });
    const hours = Array.from({ length: 25 }, (_, h) => ({ value: h, label: `${toEnglishDigits(String(h).padStart(2, '0'))}:00` }));

    return (
        <div className="cal-side">
            <MiniMonth selected={referenceDate} onPick={onPickDate} eventDays={eventDays} />

            {sources && viewingUserId === currentUserId && (
                <>
                    <Divider style={{ margin: '12px 0' }} />
                    <Text strong className="cal-side-title">تقویم‌ها</Text>
                    <div className="cal-src-list">
                        <label className="cal-src is-fixed">
                            <Checkbox checked disabled />
                            <span className="cal-src-dot" style={{ background: THEME.primary }} />
                            <span className="cal-src-name">تقویمِ من</span>
                        </label>
                        {sources.shared.map((s) => {
                            const key = sharedKey(s.OwnerUserID);
                            return (
                                <label key={key} className="cal-src">
                                    <Checkbox checked={selectedSources.includes(key)} onChange={(e) => onToggleSource(key, e.target.checked)} />
                                    <span className="cal-src-dot" style={{ background: sourceColors[key] }} />
                                    <span className="cal-src-name">{s.OwnerName}</span>
                                    <Tag className="cal-src-tag">{s.AccessLevel === 'READ' ? 'خواندنی' : 'آزاد/مشغول'}</Tag>
                                </label>
                            );
                        })}
                        {sources.units.map((u) => {
                            const key = unitKey(u.UnitID);
                            return (
                                <label key={key} className="cal-src">
                                    <Checkbox checked={selectedSources.includes(key)} onChange={(e) => onToggleSource(key, e.target.checked)} />
                                    <span className="cal-src-dot" style={{ background: sourceColors[key] }} />
                                    <span className="cal-src-name">واحد: {u.UnitName}</span>
                                </label>
                            );
                        })}
                    </div>
                    {onOpenShare && (
                        <Button size="small" icon={<ShareAltOutlined />} onClick={onOpenShare} style={{ marginTop: 8 }} block>
                            اشتراک‌گذاریِ تقویمِ من
                        </Button>
                    )}
                </>
            )}

            {canViewOthers && viewableUsers.length > 0 && (
                <>
                    <Divider style={{ margin: '12px 0' }} />
                    <Text strong className="cal-side-title">تقویم</Text>
                    <Select
                        value={viewingUserId}
                        style={{ width: '100%' }}
                        onChange={onChangeUser}
                        optionFilterProp="label"
                        showSearch
                        options={[
                            { value: currentUserId, label: 'تقویم من' },
                            ...viewableUsers.filter((u) => u.UserID !== currentUserId).map((u) => ({ value: u.UserID, label: `تقویم: ${u.FullName}` })),
                        ]}
                    />
                </>
            )}

            <Divider style={{ margin: '12px 0' }} />
            <div className="cal-side-row">
                <Text strong className="cal-side-title">فیلترها</Text>
                {activeFilterCount(filters) > 0 && (
                    <Button type="link" size="small" onClick={() => onChangeFilters(EMPTY_FILTERS)} style={{ padding: 0 }}>
                        پاک کردن
                    </Button>
                )}
            </div>
            <Text type="secondary" className="cal-side-label">ارتباطِ من با رویداد</Text>
            <Checkbox.Group
                value={filters.relations}
                onChange={(v) => set('relations', v as Relation[])}
                options={[
                    { label: 'ساختهٔ من', value: 'CREATOR' },
                    { label: 'متعلق به من', value: 'OWNER' },
                    { label: 'دعوت‌شده', value: 'ATTENDEE' },
                ]}
                className="cal-side-checks"
            />
            <Text type="secondary" className="cal-side-label">وضعیتِ رویداد</Text>
            <Checkbox.Group
                value={filters.statuses}
                onChange={(v) => set('statuses', v as EventStatus[])}
                options={[
                    { label: 'ثبت شده', value: 'CONFIRMED' },
                    { label: 'انجام شده', value: 'DONE' },
                    { label: 'لغو شده', value: 'CANCELLED' },
                ]}
                className="cal-side-checks"
            />
            <Text type="secondary" className="cal-side-label">پاسخِ من به دعوت</Text>
            <Checkbox.Group
                value={filters.responses}
                onChange={(v) => set('responses', v as ResponseStatus[])}
                options={[
                    { label: 'بدونِ پاسخ', value: 'PENDING' },
                    { label: 'پذیرفته', value: 'ACCEPTED' },
                    { label: 'شاید', value: 'TENTATIVE' },
                    { label: 'رد شده', value: 'REJECTED' },
                ]}
                className="cal-side-checks"
            />
            <Space direction="vertical" size={6} style={{ marginTop: 8, width: '100%' }}>
                <label className="cal-side-switch">
                    <Switch size="small" checked={filters.onlyWithReminder} onChange={(v) => set('onlyWithReminder', v)} />
                    <span>فقط دارایِ یادآوری</span>
                </label>
                <label className="cal-side-switch">
                    <Switch size="small" checked={filters.hideCancelled} onChange={(v) => set('hideCancelled', v)} />
                    <span>پنهان کردنِ لغوشده‌ها</span>
                </label>
            </Space>

            <Divider style={{ margin: '12px 0' }} />
            <Text strong className="cal-side-title">هفته و ساعتِ کاری</Text>
            <Text type="secondary" className="cal-side-label">روزهایِ «هفته کاری» (فقط در همین مرورگر)</Text>
            <Checkbox.Group
                value={workWeek.days}
                onChange={(v) => {
                    const days = (v as number[]).slice().sort();
                    if (days.length) onChangeWorkWeek({ ...workWeek, days });
                }}
                options={WEEKDAY_LABELS_FA.map((label, i) => ({ label, value: i }))}
                className="cal-side-checks"
            />
            <div className="cal-side-row" style={{ marginTop: 8, gap: 8 }}>
                <Select
                    size="small"
                    value={workWeek.startHour}
                    options={hours.slice(0, 24)}
                    onChange={(h) => h < workWeek.endHour && onChangeWorkWeek({ ...workWeek, startHour: h })}
                    style={{ flex: 1 }}
                    aria-label="شروعِ ساعتِ کاری"
                />
                <span>تا</span>
                <Select
                    size="small"
                    value={workWeek.endHour}
                    options={hours.slice(1)}
                    onChange={(h) => h > workWeek.startHour && onChangeWorkWeek({ ...workWeek, endHour: h })}
                    style={{ flex: 1 }}
                    aria-label="پایانِ ساعتِ کاری"
                />
            </div>

            <style>{`
                .cal-side-title { display: block; margin-bottom: 6px; }
                .cal-side-label { display: block; font-size: 11px; margin: 8px 0 4px; }
                .cal-side-row { display: flex; align-items: center; justify-content: space-between; }
                .cal-side-checks { display: flex; flex-wrap: wrap; gap: 4px 0; }
                .cal-side-checks .ant-checkbox-wrapper { width: 50%; margin-inline-start: 0; font-size: 12px; }
                .cal-src-list { display: flex; flex-direction: column; gap: 4px; }
                .cal-src { display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; min-width: 0; }
                .cal-src.is-fixed { cursor: default; }
                .cal-src-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
                .cal-src-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
                .cal-src-tag { margin: 0; font-size: 10px; line-height: 16px; padding: 0 4px; }
                .cal-side-switch { display: flex; align-items: center; gap: 8px; font-size: 12px; cursor: pointer; }
            `}</style>
        </div>
    );
}
