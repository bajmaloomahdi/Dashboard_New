import { useEffect, useState } from 'react';
import { Drawer, List, Button, Empty, Spin, Tag, Space, Typography, Form, Input, Select, TimePicker, Popconfirm, Divider } from 'antd';
import dayjs, { Dayjs } from 'dayjs';
import { BellOutlined, PlusOutlined, DeleteOutlined, EditOutlined, ClockCircleOutlined } from '@ant-design/icons';
import PersianDateInput from '../../Components/PersianDateInput';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import type { CalendarPermission, UserReminder } from '../../Types';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { toApiDateTime } from '../../Utils/jalaliCalendar';

const { Text } = Typography;

interface UserOption {
    UserID: number;
    FullName: string;
}

interface RemindersDrawerProps {
    open: boolean;
    onClose: () => void;
    users: UserOption[];
    permissions: CalendarPermission[];
    currentUserId: number;
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function api(url: string, method: string, body?: any) {
    const res = await fetch(url, {
        method,
        headers: {
            'X-XSRF-TOKEN': getXsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    return { success: !!data.success, message: data.message || (res.ok ? 'انجام شد.' : 'خطا در ارتباط با سرور') };
}

export default function RemindersDrawer({ open, onClose, users, permissions, currentUserId }: RemindersDrawerProps) {
    const canForOthers = permissions.includes('CALENDAR_CREATE_FOR_OTHERS');
    const [loading, setLoading] = useState(false);
    const [reminders, setReminders] = useState<UserReminder[]>([]);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form] = Form.useForm<{ Title: string; RDate: string | null; RTime: Dayjs | null; ForUserID: number }>();
    const [saving, setSaving] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const load = async () => {
        setLoading(true);
        try {
            const res = await fetch('/calendar/reminders', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setReminders(data.reminders || []);
        } catch {
            /* بی‌صدا */
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open) {
            load();
            setShowForm(false);
            setEditingId(null);
        }
    }, [open]);

    const openCreate = () => {
        setEditingId(null);
        form.setFieldsValue({ Title: '', RDate: null, RTime: dayjs().add(1, 'hour'), ForUserID: currentUserId });
        setShowForm(true);
    };

    const openEdit = (r: UserReminder) => {
        if (r.EntityType !== 'STANDALONE') return;
        setEditingId(r.ReminderID);
        const d = new Date(r.RemindAt);
        form.setFieldsValue({
            Title: r.Title || '',
            RDate: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`,
            RTime: dayjs(d),
            ForUserID: currentUserId,
        });
        setShowForm(true);
    };

    const submit = async () => {
        try {
            const v = await form.validateFields();
            setSaving(true);
            const base = v.RDate ? new Date(v.RDate) : new Date();
            if (v.RTime) base.setHours(v.RTime.hour(), v.RTime.minute(), 0, 0);
            const payload: any = { Title: v.Title, RemindAt: toApiDateTime(base) };

            let result;
            if (editingId) {
                result = await api(`/calendar/reminders/standalone/${editingId}`, 'PUT', payload);
            } else {
                payload.ForUserID = v.ForUserID;
                result = await api('/calendar/reminders/standalone', 'POST', payload);
            }

            if (result.success) {
                notify('success', result.message);
                setShowForm(false);
                load();
            } else {
                notify('error', result.message);
            }
        } catch {
            /* validation */
        } finally {
            setSaving(false);
        }
    };

    const remove = async (id: number) => {
        const result = await api(`/calendar/reminders/${id}`, 'DELETE');
        if (result.success) {
            notify('success', result.message);
            load();
        } else {
            notify('error', result.message);
        }
    };

    return (
        <>
            <Drawer
                title={
                    <Space>
                        <BellOutlined />
                        یادآوری‌های من
                    </Space>
                }
                open={open}
                onClose={onClose}
                width={440}
                extra={
                    !showForm && (
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            یادآوری مستقل
                        </Button>
                    )
                }
            >
                {showForm ? (
                    <Form form={form} layout="vertical" requiredMark={false}>
                        <Form.Item name="Title" label="عنوان یادآوری" rules={[{ required: true, message: 'عنوان الزامی است' }]}>
                            <Input placeholder="مثلا: ارسال گزارش فروش" maxLength={250} size="large" />
                        </Form.Item>
                        <Space style={{ width: '100%' }} size={12}>
                            <Form.Item name="RDate" label="تاریخ" rules={[{ required: true, message: 'الزامی است' }]} style={{ flex: 1 }}>
                                <PersianDateInput value={null as any} onChange={() => {}} />
                            </Form.Item>
                            <Form.Item name="RTime" label="ساعت" rules={[{ required: true, message: 'الزامی است' }]}>
                                <TimePicker format="HH:mm" size="large" style={{ width: 130 }} />
                            </Form.Item>
                        </Space>
                        {!editingId && canForOthers && (
                            <Form.Item name="ForUserID" label="برای کاربر">
                                <Select
                                    size="large"
                                    showSearch
                                    optionFilterProp="label"
                                    options={[
                                        { value: currentUserId, label: 'خودم' },
                                        ...users.filter((u) => u.UserID !== currentUserId).map((u) => ({ value: u.UserID, label: u.FullName })),
                                    ]}
                                />
                            </Form.Item>
                        )}
                        <Space>
                            <Button type="primary" loading={saving} onClick={submit}>
                                {editingId ? 'ذخیره' : 'ثبت یادآوری'}
                            </Button>
                            <Button onClick={() => setShowForm(false)}>انصراف</Button>
                        </Space>
                        <Divider />
                    </Form>
                ) : null}

                <Spin spinning={loading}>
                    {reminders.length === 0 && !loading ? (
                        <Empty description="یادآوری فعالی ندارید" />
                    ) : (
                        <List
                            dataSource={reminders}
                            renderItem={(r) => {
                                const isStandalone = r.EntityType === 'STANDALONE';
                                const createdByOther = r.CreatedByUserID != null && r.CreatedByUserID !== currentUserId;
                                return (
                                    <List.Item
                                        actions={
                                            isStandalone
                                                ? [
                                                      <Button key="e" type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />,
                                                      <Popconfirm
                                                          key="d"
                                                          title="حذف یادآوری؟"
                                                          okText="بله"
                                                          cancelText="خیر"
                                                          onConfirm={() => remove(r.ReminderID)}
                                                      >
                                                          <Button type="text" size="small" danger icon={<DeleteOutlined />} />
                                                      </Popconfirm>,
                                                  ]
                                                : [
                                                      <Popconfirm
                                                          key="d"
                                                          title="حذف یادآوری این رویداد؟"
                                                          okText="بله"
                                                          cancelText="خیر"
                                                          onConfirm={() => remove(r.ReminderID)}
                                                      >
                                                          <Button type="text" size="small" danger icon={<DeleteOutlined />} />
                                                      </Popconfirm>,
                                                  ]
                                        }
                                    >
                                        <List.Item.Meta
                                            avatar={<BellOutlined style={{ color: '#faad14', fontSize: 18 }} />}
                                            title={
                                                <Space>
                                                    <Text strong>{r.Title || '(بدون عنوان)'}</Text>
                                                    <Tag color={isStandalone ? 'blue' : 'green'} style={{ borderRadius: 6 }}>
                                                        {isStandalone ? 'مستقل' : 'رویداد'}
                                                    </Tag>
                                                    {createdByOther && (
                                                        <Tag color="purple" style={{ borderRadius: 6 }}>
                                                            از طرف: {r.CreatorName}
                                                        </Tag>
                                                    )}
                                                </Space>
                                            }
                                            description={
                                                <Space size={4}>
                                                    <ClockCircleOutlined />
                                                    <Text type="secondary">{gregorianToJalaliDateTimeDisplay(r.RemindAt)}</Text>
                                                    {Number(r.IsSent) === 1 && <Tag style={{ borderRadius: 6 }}>نمایش داده شده</Tag>}
                                                </Space>
                                            }
                                        />
                                    </List.Item>
                                );
                            }}
                        />
                    )}
                </Spin>
            </Drawer>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />
        </>
    );
}
