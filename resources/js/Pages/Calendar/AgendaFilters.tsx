import { Button, Checkbox, Input } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import PersianDateInput from '../../Components/PersianDateInput';

export default function AgendaFilters({ agendaFrom, setAgendaFrom, agendaQuery, setAgendaQuery, agendaStatuses, setAgendaStatuses, searchPlaceholder = 'جستجو در رویدادها و کاربران مرتبط…' }: {
    agendaFrom: string | null;
    setAgendaFrom: (value: string | null) => void;
    agendaQuery: string;
    setAgendaQuery: (value: string) => void;
    agendaStatuses: string[];
    setAgendaStatuses: (value: string[]) => void;
    searchPlaceholder?: string;
}) {
    return (
        <>
        <div className="cal-agenda-filters">
            <div className="cal-agenda-from">
                <span>از تاریخ:</span>
                <PersianDateInput value={agendaFrom} onChange={setAgendaFrom} size="middle" placeholder="همهٔ رویدادها" />
                {agendaFrom && (
                    <Button size="small" type="link" onClick={() => setAgendaFrom(null)}>
                        همه
                    </Button>
                )}
            </div>
            <Input
                allowClear
                prefix={<SearchOutlined style={{ color: '#bfbfbf' }} />}
                placeholder={searchPlaceholder}
                value={agendaQuery}
                onChange={(e) => setAgendaQuery(e.target.value)}
                className="cal-agenda-search"
            />
        </div>
        <div className="cal-agenda-statuses">
            <span>وضعیت:</span>
            <Checkbox.Group
                value={agendaStatuses}
                onChange={(v) => setAgendaStatuses(v as string[])}
                options={[
                    { label: 'ثبت شده', value: 'CONFIRMED' },
                    { label: 'انجام شده', value: 'DONE' },
                    { label: 'لغو شده', value: 'CANCELLED' },
                ]}
            />
        </div>
            <style>{`
                .cal-agenda-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
                .cal-agenda-from { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; }
                .cal-agenda-search { flex: 1; min-width: 200px; max-width: 360px; }
                .cal-agenda-statuses { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 13px; font-weight: 600; }
            `}</style>
        </>
    );
}
