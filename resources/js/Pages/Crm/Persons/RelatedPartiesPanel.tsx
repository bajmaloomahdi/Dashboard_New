import { Table, Tag, Space, Typography, Empty } from 'antd';
import { CheckCircleOutlined, StopOutlined, StarFilled, BankOutlined, UserOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { router } from '@inertiajs/react';
import { toBool } from '../../../Utils/bool';

const { Text, Link } = Typography;

interface PartyRelation {
    RelationID: number;
    PartyID: number;
    PartyDisplayName: string;
    PartyNature: 'INDIVIDUAL' | 'LEGAL';
    PositionName: string | null;
    RoleNames: string | null;
    IsPrimaryContact: boolean | number | string;
    IsActive: boolean | number | string;
}

interface RelatedPartiesPanelProps {
    items: PartyRelation[];
}

/**
 * نمایشِ فقط-خواندنیِ رابطه‌هایِ این مخاطب با طرف‌حساب‌ها — سمتِ دیگرِ همان
 * رابطه‌ای که از صفحهٔ طرف‌حساب (RelationsPanel) مدیریت می‌شود. مدیریت اینجا
 * اضافه نشده چون RelationsPanel حولِ «طرف‌حسابِ ثابت + انتخابِ شخص» ساخته شده
 * و معکوس‌کردنِ آن یک بازطراحیِ واقعی می‌خواهد، نه استفادهٔ مجدد.
 */
export default function RelatedPartiesPanel({ items }: RelatedPartiesPanelProps) {
    const columns: ColumnsType<PartyRelation> = [
        {
            title: 'طرف‌حساب',
            key: 'party',
            render: (_, r) => (
                <Space size={4}>
                    {toBool(r.IsPrimaryContact) ? <StarFilled style={{ color: '#F59E0B', fontSize: 12 }} /> : null}
                    <Link onClick={() => router.visit(`/crm/parties/${r.PartyID}`)}>{r.PartyDisplayName}</Link>
                </Space>
            ),
        },
        {
            title: 'ماهیت',
            key: 'nature',
            width: 100,
            render: (_, r) => {
                const isLegal = r.PartyNature === 'LEGAL';
                return (
                    <Tag color={isLegal ? 'geekblue' : 'purple'} style={{ borderRadius: 6 }} icon={isLegal ? <BankOutlined /> : <UserOutlined />}>
                        {isLegal ? 'حقوقی' : 'حقیقی'}
                    </Tag>
                );
            },
        },
        { title: 'سمت', dataIndex: 'PositionName', key: 'PositionName', render: (v) => v || <Text type="secondary">—</Text> },
        { title: 'نقش‌ها', dataIndex: 'RoleNames', key: 'RoleNames', render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'وضعیتِ رابطه',
            key: 'status',
            width: 120,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
    ];

    return (
        <Table
            rowKey="RelationID"
            columns={columns}
            dataSource={items}
            pagination={false}
            locale={{ emptyText: <Empty description="این مخاطب به هیچ طرف‌حسابی مرتبط نیست" /> }}
        />
    );
}
