import { Table, Tag, Typography, Progress, Alert, Empty, Space } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { Link } from '@inertiajs/react';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

/** یک پروژهٔ مرتبط با طرف‌حساب (sp_Crm_GetPartyProjects — پچِ ۱۳۵) یا فردِ مرتبط (sp_Crm_GetPersonProjects — پچِ ۱۳۶) */
export interface PartyProject {
    ProjectID: number | string;
    ProjectCode: string | null;
    ProjectTitle: string;
    ProjectStatusTitle: string | null;
    PriorityName: string | null;
    PriorityColor: string | null;
    ProgressPercent: number | string | null;
    StartDate: string | null;
    PlannedEndDate: string | null;
    ActualEndDate: string | null;
    IsActive: boolean | number | string;
    ResponsibleName: string;
    /** طرف‌حساب */
    IsOwner?: boolean | number | string;
    IsContractor?: boolean | number | string;
    /** فرد: طرف‌حساب‌هایی که پروژه از راهِ آن‌ها مرتبط است، مثلاً «شرکتِ الف (مالک)، شرکتِ ب (پیمانکار)» */
    ViaParties?: string | null;
    /** فرد: کاربرِ متصل به این فرد عضوِ پروژه است */
    IsMember?: boolean | number | string;
}

interface PartyProjectsPanelProps {
    items: PartyProject[];
    /** پروژه‌هایِ مرتبطی که کاربر عضوشان نیست (فقط تعداد) */
    hiddenCount: number;
    /** «party» (پیش‌فرض) = نقشِ طرف‌حساب؛ «person» = ارتباطِ فرد (از راهِ طرف‌حساب یا عضویت) */
    mode?: 'party' | 'person';
}

/** تبِ «پروژه‌ها»ی طرف‌حساب (مالک/پیمانکار) و فردِ مرتبط (از راهِ طرف‌حساب‌هایش یا عضویت) */
export default function PartyProjectsPanel({ items, hiddenCount, mode = 'party' }: PartyProjectsPanelProps) {
    const isPerson = mode === 'person';
    const subject = isPerson ? 'این فرد' : 'این طرف‌حساب';
    const columns: ColumnsType<PartyProject> = [
        { title: 'کد', dataIndex: 'ProjectCode', key: 'code', width: 110, render: (v) => <Text dir="ltr">{v || '—'}</Text> },
        {
            title: 'عنوانِ پروژه',
            key: 'title',
            render: (_, p) => (
                <Space size={6} wrap>
                    <Link href={`/projects/${p.ProjectID}`}>{p.ProjectTitle}</Link>
                    {p.PriorityName ? (
                        <Tag style={p.PriorityColor ? { color: p.PriorityColor, borderColor: p.PriorityColor, background: `${p.PriorityColor}18` } : undefined}>
                            {p.PriorityName}
                        </Tag>
                    ) : null}
                    {toBool(p.IsActive) ? null : <Tag>غیرفعال</Tag>}
                </Space>
            ),
        },
        isPerson
            ? {
                  title: 'ارتباط',
                  key: 'role',
                  width: 240,
                  render: (_, p) => (
                      <Space size={4} wrap>
                          {toBool(p.IsMember) ? <Tag color="green">عضوِ پروژه</Tag> : null}
                          {p.ViaParties ? <Text style={{ fontSize: 12 }}>از راهِ {p.ViaParties}</Text> : null}
                      </Space>
                  ),
              }
            : {
                  title: 'نقشِ طرف‌حساب',
                  key: 'role',
                  width: 150,
                  render: (_, p) => (
                      <Space size={4} wrap>
                          {toBool(p.IsOwner) ? <Tag color="gold">مالکِ پروژه</Tag> : null}
                          {toBool(p.IsContractor) ? <Tag color="blue">پیمانکار</Tag> : null}
                      </Space>
                  ),
              },
        { title: 'وضعیت', dataIndex: 'ProjectStatusTitle', key: 'status', width: 120, render: (v) => (v ? <Tag>{v}</Tag> : '—') },
        { title: 'مسئول', dataIndex: 'ResponsibleName', key: 'responsible', width: 150, render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'پیشرفت',
            key: 'progress',
            width: 140,
            render: (_, p) => <Progress percent={Number(p.ProgressPercent) || 0} size="small" />,
        },
        {
            title: 'شروع — پایانِ برنامه',
            key: 'dates',
            width: 190,
            render: (_, p) => (
                <Text style={{ fontSize: 12 }}>
                    {p.StartDate ? gregorianToJalaliDisplay(p.StartDate) : '—'} — {p.PlannedEndDate ? gregorianToJalaliDisplay(p.PlannedEndDate) : '—'}
                </Text>
            ),
        },
    ];

    return (
        <>
            {hiddenCount > 0 ? (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 12, borderRadius: 8 }}
                    message={`${hiddenCount} پروژهٔ دیگر هم به ${subject} مرتبط است که شما عضوِ آن نیستید و نمایش داده نمی‌شود.`}
                />
            ) : null}
            <Table
                rowKey={(p) => String(p.ProjectID)}
                columns={columns}
                dataSource={items}
                pagination={items.length > 10 ? { pageSize: 10 } : false}
                scroll={{ x: 900 }}
                size="middle"
                locale={{
                    emptyText: (
                        <Empty
                            description={
                                hiddenCount > 0
                                    ? `پروژه‌ای که عضوش باشید با ${subject} مرتبط نیست`
                                    : isPerson
                                      ? 'این فرد از راهِ طرف‌حساب‌هایش یا عضویت با هیچ پروژه‌ای مرتبط نیست'
                                      : 'این طرف‌حساب مالک یا پیمانکارِ هیچ پروژه‌ای نیست'
                            }
                        />
                    ),
                }}
            />
        </>
    );
}
