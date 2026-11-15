import { useEffect, useState } from 'react';
import { Alert, Button, Empty, Table, Tag } from 'antd';
import { crmApi } from './crmApi';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';

interface HistoryRow {
    HistoryID: number | string;
    SourceTable: string;
    RecordID: number;
    ChangedBy: number | null;
    ActionType: 'INSERT' | 'UPDATE' | 'DELETE';
    ChangedAt: string;
    ActorName: string | null;
    FieldName: string;
    OldValue: string | null;
    NewValue: string | null;
}

const sections: Record<string, string> = {
    CrmParties: 'پروفایل طرف‌حساب', CrmPersons: 'پروفایل فرد مرتبط',
    CrmContacts: 'اطلاعات تماس', CrmAddresses: 'آدرس', CrmPartyPersonRelations: 'ارتباط',
};
const fields: Record<string, string> = {
    OfficialName: 'نام رسمی', TradeName: 'نام تجاری', FirstName: 'نام', LastName: 'نام خانوادگی',
    Description: 'توضیحات', ContactValue: 'مقدار تماس', Extension: 'داخلی', IsActive: 'فعال',
    IsPrimary: 'اصلی', IsPrimaryContact: 'مخاطب اصلی', CooperationEndedAt: 'زمان پایان همکاری',
    IdentifierNumber: 'شناسه / کد ملی', IdentifierDate: 'تاریخ شناسایی', PartyNature: 'ماهیت',
    RegistrationNumber: 'شماره ثبت', EconomicCode: 'کد اقتصادی', AddressText: 'نشانی',
    PostalCode: 'کد پستی', PartyID: 'شناسه طرف‌حساب', PersonID: 'شناسه فرد مرتبط',
};
const legacyClassificationFields = new Set(['DepartmentID', 'PartyTypeID', 'ActivityID']);

const summaryFields: Record<string, readonly string[]> = {
    CrmParties: ['OfficialName', 'TradeName', 'CooperationEndedAt', 'IsActive', 'Description'],
    CrmPersons: ['FirstName', 'LastName', 'CooperationEndedAt', 'IsActive', 'Description'],
    CrmContacts: ['ContactValue', 'Extension', 'ContactTypeID', 'Description', 'IsActive', 'IsPrimary'],
    CrmAddresses: ['AddressText', 'PostalCode', 'CityID', 'Description', 'IsActive'],
    CrmPartyPersonRelations: ['PersonID', 'PositionID', 'IsPrimaryContact', 'IsActive'],
};

function summaryPriority(row: HistoryRow): number {
    const index = summaryFields[row.SourceTable]?.indexOf(row.FieldName) ?? -1;
    return index < 0 ? Number.MAX_SAFE_INTEGER : index;
}

const actions = { INSERT: 'ایجاد', UPDATE: 'ویرایش', DELETE: 'حذف' };

export default function RecordHistoryPanel({ entityType, entityId }: { entityType: 'party' | 'person'; entityId: number }) {
    const [items, setItems] = useState<HistoryRow[]>([]);
    const [cursor, setCursor] = useState<string>();
    const [next, setNext] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        const url = '/crm/history/' + entityType + '/' + entityId + (cursor ? '?before=' + encodeURIComponent(cursor) : '');
        crmApi(url, 'GET', undefined, controller.signal)
            .then(result => {
                if (controller.signal.aborted) return;
                if (!result.ok || !result.success) throw new Error(result.message);
                setItems(current => cursor ? [...current, ...result.items] : result.items);
                setNext(result.next ?? null);
            })
            .catch(() => { if (!controller.signal.aborted) setError('دریافت تاریخچه انجام نشد.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [entityType, entityId, cursor, retry]);

    const operations = new Map<string, HistoryRow>();
    for (const row of items) {
        if (row.SourceTable === 'CrmParties' && row.ActionType === 'UPDATE' &&
            row.NewValue === null && legacyClassificationFields.has(row.FieldName)) continue;

        const key = JSON.stringify([row.SourceTable, row.RecordID, row.ActionType, row.ChangedAt, row.ChangedBy]);
        const summary = operations.get(key);
        if (!summary || summaryPriority(row) < summaryPriority(summary)) {
            operations.set(key, row);
        }
    }
    const visibleItems = [...operations.values()];

    return <>
        {error && <Alert type="error" showIcon message={error}
            action={<Button onClick={() => setRetry(value => value + 1)}>تلاش دوباره</Button>} />}
        <Table<HistoryRow> rowKey="HistoryID" dataSource={visibleItems} loading={loading} pagination={false}
            size="small" scroll={{ x: 750 }} locale={{ emptyText: <Empty description="از زمان فعال‌سازی تاریخچه، تغییری ثبت نشده است." /> }}
            columns={[
                { title: 'زمان', dataIndex: 'ChangedAt', render: value => <bdi>{gregorianToJalaliDateTimeDisplay(value)}</bdi> },
                { title: 'کاربر', dataIndex: 'ActorName', render: value => value || '—' },
                { title: 'عملیات', dataIndex: 'ActionType', render: (action: HistoryRow['ActionType']) => <Tag>{actions[action]}</Tag> },
                { title: 'بخش', dataIndex: 'SourceTable', render: value => sections[value] ?? value },
                { title: 'فیلد', dataIndex: 'FieldName', render: value => fields[value] ?? value },
                { title: 'مقدار قبلی', dataIndex: 'OldValue', render: value => value ?? '—' },
                { title: 'مقدار جدید', dataIndex: 'NewValue', render: value => value ?? '—' },
            ]} />
        {next && <Button style={{ marginTop: 12 }} loading={loading} disabled={!!error}
            onClick={() => setCursor(next ?? undefined)}>نمایش تغییرات قدیمی‌تر</Button>}
    </>;
}