import { useEffect, useRef, useState } from 'react';
import { Modal, Select, Button, Table, Tag, Space, Typography } from 'antd';
import { PlusOutlined, DeleteOutlined, ApartmentOutlined, SolutionOutlined } from '@ant-design/icons';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { toBool } from '../../Utils/bool';

interface Contractor {
    ProjectContractorID: number;
    ProjectID: number;
    PartyID: number;
    OfficialName: string | null;
    TradeName: string | null;
    IdentifierNumber: string | null;
    PartyNature: string | null;
    PartyIsActive: boolean | number | null;
    IsActive: boolean | number;
    Date_InsertFirst: string;
}

interface PartyCandidate {
    PartyID: number;
    OfficialName: string | null;
    TradeName: string | null;
    IdentifierNumber: string | null;
    PartyNature: string | null;
    IsActive: boolean | number;
}

interface ProjectContractorsModalProps {
    open: boolean;
    onClose: () => void;
    projectId: number | null;
    projectTitle?: string;
}

/** خواندن توکن CSRF از کوکی (همان روشی که Inertia/axios استفاده می‌کند) */
function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function api(url: string, method: string, body?: any): Promise<{ success: boolean; message: string }> {
    const headers: Record<string, string> = {
        'X-XSRF-TOKEN': getXsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/json',
    };
    const res = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    return {
        success: !!data.success,
        message: data.message || (res.ok ? 'عملیات انجام شد.' : 'خطا در ارتباط با سرور'),
    };
}

/**
 * مدیریتِ پیمانکارانِ یک پروژه — دقیقاً هم‌الگو با ProjectMembersModal، با یک تفاوت:
 * چون پیمانکار از CrmParties انتخاب می‌شود (که می‌تواند فهرستِ بزرگی باشد)، به‌جایِ یک
 * Arrayِ ثابتِ از‌پیش‌بارگذاری‌شده، از یک مسیرِ جست‌وجویِ اختصاصی و محدود استفاده می‌شود
 * (`/projects/{id}/contractors/search`) — نه Endpointِ عمومیِ CRM — تا کاربری که فقط
 * اجازهٔ مدیریتِ همین پروژه را دارد (نه CRM_VIEW)، بدونِ دسترسیِ کاملِ CRM بتواند طرف‌حساب
 * جست‌وجو کند.
 */
export default function ProjectContractorsModal({
    open,
    onClose,
    projectId,
    projectTitle,
}: ProjectContractorsModalProps) {
    const [contractors, setContractors] = useState<Contractor[]>([]);
    const [loading, setLoading] = useState(false);

    const [searchOptions, setSearchOptions] = useState<PartyCandidate[]>([]);
    const [searching, setSearching] = useState(false);
    const [newParty, setNewParty] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });

    const showNotification = (type: NotificationType, message: string) =>
        setNotification({ open: true, type, message });

    const loadContractors = async (): Promise<Contractor[]> => {
        if (!projectId) return [];
        setLoading(true);
        try {
            const res = await fetch(`/projects/${projectId}/contractors`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            const list: Contractor[] = data.contractors || [];
            setContractors(list);
            return list;
        } catch {
            showNotification('error', 'خطا در بارگذاریِ پیمانکارانِ پروژه');
            return [];
        } finally {
            setLoading(false);
        }
    };

    /**
     * نمایشِ ۵ طرف‌حسابِ اول همان‌لحظه که Modal باز می‌شود — صرفاً برایِ راهنماییِ بصریِ
     * کاربر که این Select قابلِ‌جست‌وجوست؛ به‌معنایِ انتخاب یا افزوده‌شدن نیست و با شروعِ
     * تایپ، با نتایجِ واقعیِ جست‌وجو (TOP 50) جایگزین می‌شود.
     */
    const loadInitialCandidates = async (currentContractors: Contractor[]) => {
        if (!projectId) return;
        setSearching(true);
        try {
            const res = await fetch(`/projects/${projectId}/contractors/search?search=`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            const parties: PartyCandidate[] = data.parties || [];
            const active = currentContractors.filter((c) => toBool(c.IsActive));
            setSearchOptions(parties.filter((p) => !active.some((c) => c.PartyID === p.PartyID)));
        } catch {
            setSearchOptions([]);
        } finally {
            setSearching(false);
        }
    };

    useEffect(() => {
        if (open && projectId) {
            setNewParty(null);
            setSearchOptions([]);
            (async () => {
                const list = await loadContractors();
                await loadInitialCandidates(list);
            })();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, projectId]);

    const activeContractors = contractors.filter((c) => toBool(c.IsActive));

    const handleSearch = (text: string) => {
        if (!projectId) return;
        if (searchTimer.current) clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(async () => {
            setSearching(true);
            try {
                const res = await fetch(`/projects/${projectId}/contractors/search?search=${encodeURIComponent(text)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                const parties: PartyCandidate[] = data.parties || [];
                // طرف‌حساب‌هایی که همین الان پیمانکارِ فعالِ این پروژه‌اند، دوباره در نتیجه نشان داده نشوند.
                setSearchOptions(parties.filter((p) => !activeContractors.some((c) => c.PartyID === p.PartyID)));
            } catch {
                setSearchOptions([]);
            } finally {
                setSearching(false);
            }
        }, 350);
    };

    const handleAdd = async () => {
        if (!projectId || !newParty) {
            showNotification('warning', 'یک طرف‌حساب را انتخاب کنید');
            return;
        }
        setSaving(true);
        const result = await api(`/projects/${projectId}/contractors`, 'POST', { PartyID: newParty });
        setSaving(false);
        if (result.success) {
            setNewParty(null);
            showNotification('success', result.message);
            const list = await loadContractors();
            await loadInitialCandidates(list);
        } else {
            showNotification('error', result.message);
        }
    };

    const handleRemove = async (partyId: number) => {
        if (!projectId) return;
        setSaving(true);
        const result = await api(`/projects/${projectId}/contractors?PartyID=${partyId}`, 'DELETE');
        setSaving(false);
        if (result.success) {
            showNotification('success', result.message);
            const list = await loadContractors();
            await loadInitialCandidates(list);
        } else {
            showNotification('error', result.message);
        }
    };

    const columns = [
        {
            title: '#',
            key: 'rowNumber',
            width: 50,
            align: 'center' as const,
            render: (_: any, __: Contractor, index: number) => index + 1,
        },
        {
            title: 'طرف‌حساب',
            key: 'name',
            render: (_: any, rec: Contractor) => (
                <Space>
                    <ApartmentOutlined style={{ color: '#667eea' }} />
                    <div>
                        <div>
                            {rec.OfficialName || `طرف‌حسابِ #${rec.PartyID}`}
                            {!toBool(rec.PartyIsActive) ? (
                                <Tag color="red" style={{ marginInlineStart: 6 }}>غیرفعال در CRM</Tag>
                            ) : null}
                        </div>
                        {rec.IdentifierNumber ? (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {rec.IdentifierNumber}
                            </Typography.Text>
                        ) : null}
                    </div>
                </Space>
            ),
        },
        {
            title: 'تاریخ افزودن',
            dataIndex: 'Date_InsertFirst',
            key: 'Date_InsertFirst',
            width: 160,
            render: (d: string | null) => (d ? <Typography.Text>{gregorianToJalaliDateTimeDisplay(d)}</Typography.Text> : '—'),
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 100,
            align: 'center' as const,
            render: (_: any, rec: Contractor) => (
                <Button
                    danger
                    type="text"
                    icon={<DeleteOutlined />}
                    disabled={saving}
                    title="حذفِ پیمانکار"
                    onClick={() => handleRemove(rec.PartyID)}
                />
            ),
        },
    ];

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <SolutionOutlined style={{ color: '#667eea', fontSize: 20 }} />
                    <span>مدیریتِ پیمانکارانِ پروژه{projectTitle ? `: ${projectTitle}` : ''}</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={640}
            className="responsive-modal"
            footer={[
                <Button key="close" onClick={onClose}>
                    بستن
                </Button>,
            ]}
        >
            <Space style={{ marginBottom: 16, width: '100%' }} direction="vertical">
                <Typography.Text style={{ fontSize: 13 }}>
                    برای افزودن پیمانکار، نام یا بخشی از نام طرف‌حساب را جست‌وجو کنید.
                </Typography.Text>
                <Space.Compact style={{ width: '100%' }}>
                    <Select
                        placeholder="جست‌وجویِ طرف‌حساب (نام یا شناسهٔ ملی/شناسه)..."
                        showSearch
                        virtual={false}
                        filterOption={false}
                        loading={searching}
                        style={{ flex: 1 }}
                        value={newParty}
                        onSearch={handleSearch}
                        onChange={(v) => setNewParty(v)}
                        notFoundContent={searching ? 'در حالِ جست‌وجو...' : 'موردی یافت نشد'}
                        options={searchOptions.map((p) => ({
                            value: p.PartyID,
                            label: `${p.OfficialName || `#${p.PartyID}`}${p.IdentifierNumber ? ' — ' + p.IdentifierNumber : ''}`,
                        }))}
                    />
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        loading={saving}
                        onClick={handleAdd}
                        disabled={!newParty}
                    >
                        افزودن
                    </Button>
                </Space.Compact>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    پیمانکار از بینِ طرف‌حساب‌هایِ فعالِ CRM انتخاب می‌شود؛ مشخصاتِ آن مستقیماً از CRM خوانده می‌شود.
                </Typography.Text>
            </Space>

            <Table
                className="unified-table"
                columns={columns}
                dataSource={activeContractors}
                rowKey="ProjectContractorID"
                loading={loading}
                size="middle"
                pagination={false}
                scroll={{ x: 'max-content' }}
                locale={{ emptyText: 'هنوز پیمانکاری تعریف نشده است' }}
            />

            <style>{`
                .unified-table .ant-table-thead > tr > th {
                    background: #EEEBFB !important;
                }
            `}</style>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />
        </Modal>
    );
}
