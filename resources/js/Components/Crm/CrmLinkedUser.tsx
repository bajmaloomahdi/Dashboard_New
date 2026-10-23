import { useState } from 'react';
import { Alert, Button, Modal, Popconfirm, Select, Space, Tag, Typography } from 'antd';
import { DisconnectOutlined, EditOutlined, UserAddOutlined, UserOutlined } from '@ant-design/icons';
import { crmApi } from './crmApi';
import { USER_TYPE_META, normalizeUserType } from '../UserTypeTag';

const { Text } = Typography;

interface LinkableUser {
    UserID: number;
    Name: string;
    UserName: string;
    UserType: string;
    CrmLinkName: string | null;
}

/**
 * کاربرِ متصل به طرف‌حساب/مخاطب (پچِ ۱۲۱) در صفحهٔ اصلیِ CRM: نمایش، تعیین/تغییر و برداشتنِ اتصال.
 * کاربری که به مخاطب/طرف‌حسابِ دیگری وصل است قابلِ انتخاب نیست (اول باید از آنجا جدا شود).
 */
export default function CrmLinkedUserAction({ entity, id, userId, userName, userType, canManage, onSaved }: {
    entity: 'parties' | 'persons';
    id: number;
    userId?: number | string | null;
    userName?: string | null;
    /** فقط مخاطب (پچِ ۱۲۲): کاربرِ متصل «داخلی» است یا «مخاطب» */
    userType?: string | null;
    canManage: boolean;
    onSaved: (message: string, ok: boolean) => void;
}) {
    const currentId = userId ? Number(userId) : null;
    const isPerson = entity === 'persons';
    const [open, setOpen] = useState(false);
    const [users, setUsers] = useState<LinkableUser[]>([]);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<number | null>(null);
    const [type, setType] = useState<'INTERNAL' | 'PERSON'>('PERSON');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const openModal = async () => {
        setSelected(currentId);
        setType(normalizeUserType(userType) === 'INTERNAL' ? 'INTERNAL' : 'PERSON');
        setError(null);
        setOpen(true);
        setLoading(true);
        const res = await crmApi('/crm/linkable-users', 'GET');
        setLoading(false);
        if (res.ok && res.success) setUsers((res.users || []).map((u: any) => ({ ...u, UserID: Number(u.UserID) })));
        else setError(res.message);
    };

    const send = async (uid: number | null) => {
        setSaving(true);
        setError(null);
        const res = await crmApi(`/crm/${entity}/${id}/user`, 'POST', isPerson && uid ? { userId: uid, userType: type } : { userId: uid });
        setSaving(false);
        if (!res.ok || !res.success) {
            if (open) setError(res.message);
            else onSaved(res.message, false);
            return;
        }
        setOpen(false);
        onSaved(res.message, true);
    };

    return (
        <Space size={8} wrap>
            {currentId ? (
                <Tag icon={<UserOutlined />} color="blue" style={{ marginInlineEnd: 0 }}>
                    {userName || `#${currentId}`}
                    {isPerson && userType ? ` (${USER_TYPE_META[normalizeUserType(userType)].label})` : ''}
                </Tag>
            ) : (
                <span>ندارد</span>
            )}
            {canManage ? (
                currentId ? (
                    <>
                        <Button size="small" icon={<EditOutlined />} onClick={openModal}>تغییر</Button>
                        <Popconfirm title="اتصالِ این کاربر برداشته شود؟ (کاربر «داخلی» می‌شود)" okText="بله" cancelText="خیر" onConfirm={() => send(null)}>
                            <Button size="small" icon={<DisconnectOutlined />} loading={saving && !open}>برداشتن</Button>
                        </Popconfirm>
                    </>
                ) : (
                    <Button size="small" icon={<UserAddOutlined />} onClick={openModal}>تعیینِ کاربر</Button>
                )
            ) : null}

            <Modal
                title={entity === 'parties' ? 'کاربرِ این طرف‌حساب' : 'کاربرِ این مخاطب'}
                open={open}
                onCancel={() => !saving && setOpen(false)}
                maskClosable={false}
                okText="ذخیره"
                cancelText="انصراف"
                okButtonProps={{ disabled: !selected }}
                onOk={() => selected && send(selected)}
                confirmLoading={saving}
                destroyOnClose
            >
                {error ? <Alert type="error" showIcon message={error} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}
                {isPerson ? (
                    <div style={{ marginBottom: 12 }}>
                        <Text style={{ display: 'block', fontSize: 12, marginBottom: 4 }}>نوعِ کاربر</Text>
                        <Select
                            style={{ width: '100%' }}
                            size="large"
                            value={type}
                            onChange={(v) => setType(v)}
                            options={[
                                { value: 'INTERNAL', label: 'کاربرِ داخلی (این مخاطب برایِ آدرس و تلفنِ اوست)' },
                                { value: 'PERSON', label: 'مخاطب' },
                            ]}
                        />
                    </div>
                ) : null}
                <Select
                    showSearch
                    style={{ width: '100%' }}
                    size="large"
                    loading={loading}
                    placeholder="جست‌وجو و انتخابِ کاربر…"
                    value={selected ?? undefined}
                    onChange={(v) => {
                        setSelected(Number(v));
                        // کاربرِ داخلی پیش‌فرض داخلی می‌ماند
                        const picked = users.find((u) => u.UserID === Number(v));
                        if (picked && isPerson) setType(normalizeUserType(picked.UserType) === 'INTERNAL' ? 'INTERNAL' : 'PERSON');
                    }}
                    optionFilterProp="search"
                    notFoundContent={loading ? 'در حالِ بارگذاری…' : 'کاربری پیدا نشد'}
                    options={users.map((u) => {
                        const uType = normalizeUserType(u.UserType);
                        // متصل به رکوردِ دیگر (کاربرِ داخلی هم ممکن است مخاطبِ دیگری داشته باشد)
                        const linkedElsewhere = !!u.CrmLinkName && u.UserID !== currentId;
                        return {
                            value: u.UserID,
                            search: `${u.Name} ${u.UserName}`,
                            disabled: linkedElsewhere,
                            label: (
                                <span>
                                    {u.Name} <Text type="secondary" style={{ fontSize: 12 }}>@{u.UserName}</Text>
                                    {linkedElsewhere ? <Text type="secondary" style={{ fontSize: 12 }}> — {USER_TYPE_META[uType].label}: {u.CrmLinkName}</Text> : null}
                                </span>
                            ),
                        };
                    })}
                />
                <Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                    {isPerson ? '' : 'نوعِ کاربرِ انتخاب‌شده «طرف‌حساب» می‌شود. '}کاربری که به رکوردِ دیگری متصل است اول باید از آنجا جدا شود.
                </Text>
            </Modal>
        </Space>
    );
}
