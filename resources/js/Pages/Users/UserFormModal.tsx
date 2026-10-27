import { useEffect, useRef, useState } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography, Select, Alert, Spin } from 'antd';
import {
    UserOutlined,
    LockOutlined,
    MailOutlined,
    PhoneOutlined,
    IdcardOutlined,
    SaveOutlined,
    CloseOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';
import { USER_TYPE_OPTIONS, UserType, normalizeUserType } from '../../Components/UserTypeTag';

const { Text } = Typography;

interface User {
    UserID: number;
    UserCode: string;
    UserName: string;
    FirstName: string | null;
    LastName: string | null;
    Email: string | null;
    Mobile: string | null;
    Description: string | null;
    UserType?: string | null;
    CrmPersonID?: number | string | null;
    CrmPartyID?: number | string | null;
    CrmLinkName?: string | null;
}

interface CrmOption {
    id: number;
    name: string;
    linkedUserId: number | null;
    linkedUserName: string | null;
}

interface UserFormModalProps {
    open: boolean;
    onClose: () => void;
    editingUser: User | null;
}

export default function UserFormModal({ open, onClose, editingUser }: UserFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingUser;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        UserName: '',
        Password: '',
        FirstName: '',
        LastName: '',
        Email: '',
        Mobile: '',
        Description: '',
        UserType: 'INTERNAL' as UserType,
        CrmPersonID: null as number | null,
        CrmPartyID: null as number | null,
    });

    // جست‌وجویِ مخاطب/طرف‌حساب برایِ نوعِ «مخاطب»/«طرف‌حساب» (پچِ ۱۲۱)
    const [crmOptions, setCrmOptions] = useState<CrmOption[]>([]);
    const [crmLoading, setCrmLoading] = useState(false);
    const [crmLinkError, setCrmLinkError] = useState<string | null>(null);
    const searchTimer = useRef<number | undefined>(undefined);
    const searchSeq = useRef(0);
    // پچِ ۱۲۲: داخلی و مخاطب → فهرستِ مخاطبان (برایِ داخلی اختیاری)؛ طرف‌حساب → فهرستِ طرف‌حساب‌ها
    const linkKind = (type: UserType) => (type === 'PARTY' ? 'PARTY' : 'PERSON');
    const loadCrmOptions = (type: UserType, search: string) => {
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(async () => {
            const seq = ++searchSeq.current;
            setCrmLoading(true);
            try {
                const res = await fetch(`/users/crm-options?type=${linkKind(type)}&search=${encodeURIComponent(search)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const json = res.ok ? await res.json() : { items: [] };
                if (seq === searchSeq.current) {
                    setCrmOptions((json.items || []).map((i: any) => ({ ...i, id: Number(i.id), linkedUserId: i.linkedUserId == null ? null : Number(i.linkedUserId) })));
                }
            } finally {
                if (seq === searchSeq.current) setCrmLoading(false);
            }
        }, 300);
    };

    // پر کردن فرم هنگام ویرایش
    useEffect(() => {
        if (open) {
            if (editingUser) {
                const formData = {
                    UserName: editingUser.UserName || '',
                    Password: '',
                    FirstName: editingUser.FirstName || '',
                    LastName: editingUser.LastName || '',
                    Email: editingUser.Email || '',
                    Mobile: editingUser.Mobile || '',
                    Description: editingUser.Description || '',
                    UserType: normalizeUserType(editingUser.UserType),
                    CrmPersonID: editingUser.CrmPersonID ? Number(editingUser.CrmPersonID) : null,
                    CrmPartyID: editingUser.CrmPartyID ? Number(editingUser.CrmPartyID) : null,
                };
                setData(formData);
                form.setFieldsValue(formData);
                const linkedId = formData.UserType === 'PARTY' ? formData.CrmPartyID : formData.CrmPersonID;
                setCrmOptions(linkedId && editingUser.CrmLinkName ? [{ id: linkedId, name: editingUser.CrmLinkName, linkedUserId: editingUser.UserID, linkedUserName: null }] : []);
                loadCrmOptions(formData.UserType, '');
            } else {
                reset();
                form.resetFields();
                setCrmOptions([]);
                loadCrmOptions('INTERNAL', '');
            }
            setCrmLinkError(null);
            clearErrors();
        }
    }, [open, editingUser]);

    /**
     * ارسال فرم
     */
    const handleSubmit = () => {
        form.validateFields().then(() => {
            if (data.UserType === 'PERSON' && !data.CrmPersonID) return setCrmLinkError('فردِ مرتبطِ این کاربر را انتخاب کنید.');
            if (data.UserType === 'PARTY' && !data.CrmPartyID) return setCrmLinkError('طرف‌حسابِ این کاربر را انتخاب کنید.');
            const options = {
                preserveScroll: true,
                onSuccess: () => {
                    handleClose();
                },
                onError: () => {
                    // خطاها توسط NotificationModal در Index نمایش داده میشن
                },
            };

            if (isEdit) {
                put(`/users/${editingUser!.UserID}`, options);
            } else {
                post('/users', options);
            }
        });
    };

    /**
     * بستن مودال
     */
    const handleClose = () => {
        form.resetFields();
        reset();
        clearErrors();
        onClose();
    };

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <UserOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش کاربر' : 'ایجاد کاربر جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={700}
            className="responsive-modal"
            footer={[
                <Button
                    key="cancel"
                    icon={<CloseOutlined />}
                    onClick={handleClose}
                    disabled={processing}
                >
                    انصراف
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={processing}
                    onClick={handleSubmit}
                    style={{
                        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                        border: 'none',
                    }}
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد کاربر'}
                </Button>,
            ]}
            styles={{ body: { paddingTop: 24 } }}
        >
            {errors.UserType ? <Alert type="error" showIcon message={errors.UserType} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}
            <Form
                form={form}
                layout="vertical"
                requiredMark
            >
                <Row gutter={16}>
                    {/* نام کاربری */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام کاربری"
                            name="UserName"
                            rules={[
                                { required: !isEdit, message: 'نام کاربری الزامی است' },
                                { min: 3, message: 'حداقل 3 کاراکتر' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.UserName ? 'error' : ''}
                            help={errors.UserName}
                            extra={isEdit ? 'نام کاربری قابل تغییر نیست' : undefined}
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: ali_ahmadi"
                                value={data.UserName}
                                onChange={(e) => setData('UserName', e.target.value)}
                                disabled={isEdit}
                                size="large"
                                autoComplete="off"
                            />
                        </Form.Item>
                    </Col>

                    {/* کلمه عبور */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="کلمه عبور"
                            name="Password"
                            rules={
                                !isEdit
                                    ? [
                                          { required: true, message: 'کلمه عبور الزامی است' },
                                          { min: 6, message: 'حداقل 6 کاراکتر' },
                                      ]
                                    : []
                            }
                            validateStatus={errors.Password ? 'error' : ''}
                            help={errors.Password}
                            extra={isEdit ? 'برای تغییر پسورد، بخش جداگانه‌ای وجود دارد' : undefined}
                        >
                            <Input.Password
                                prefix={<LockOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder={isEdit ? '••••••••' : 'حداقل 6 کاراکتر'}
                                value={data.Password}
                                onChange={(e) => setData('Password', e.target.value)}
                                disabled={isEdit}
                                size="large"
                                autoComplete="new-password"
                            />
                        </Form.Item>
                    </Col>

                    {/* نام */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام"
                            name="FirstName"
                            rules={[{ max: 100, message: 'حداکثر 100 کاراکتر' }]}
                            validateStatus={errors.FirstName ? 'error' : ''}
                            help={errors.FirstName}
                        >
                            <Input
                                prefix={<UserOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="نام"
                                value={data.FirstName}
                                onChange={(e) => setData('FirstName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* نام خانوادگی */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام خانوادگی"
                            name="LastName"
                            rules={[{ max: 100, message: 'حداکثر 100 کاراکتر' }]}
                            validateStatus={errors.LastName ? 'error' : ''}
                            help={errors.LastName}
                        >
                            <Input
                                prefix={<UserOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="نام خانوادگی"
                                value={data.LastName}
                                onChange={(e) => setData('LastName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* ایمیل */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="ایمیل"
                            name="Email"
                            rules={[
                                { type: 'email', message: 'فرمت ایمیل نامعتبر است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.Email ? 'error' : ''}
                            help={errors.Email}
                        >
                            <Input
                                prefix={<MailOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="example@domain.com"
                                value={data.Email}
                                onChange={(e) => setData('Email', e.target.value)}
                                size="large"
                                type="email"
                            />
                        </Form.Item>
                    </Col>

                    {/* موبایل */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="موبایل"
                            name="Mobile"
                            rules={[
                                { max: 20, message: 'حداکثر 20 کاراکتر' },
                                {
                                    pattern: /^09\d{9}$/,
                                    message: 'شماره موبایل معتبر نیست (مثال: 09121234567)',
                                },
                            ]}
                            validateStatus={errors.Mobile ? 'error' : ''}
                            help={errors.Mobile}
                        >
                            <Input
                                prefix={<PhoneOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="09121234567"
                                value={data.Mobile}
                                onChange={(e) => setData('Mobile', e.target.value)}
                                size="large"
                                maxLength={11}
                            />
                        </Form.Item>
                    </Col>

                    {/* نوعِ کاربر (پچِ ۱۲۱) — جدا از رکوردِ متصل (پچِ ۱۲۲) */}
                    <Col xs={24} md={12}>
                        <Form.Item label="نوعِ کاربر">
                            <Select
                                size="large"
                                value={data.UserType}
                                options={USER_TYPE_OPTIONS}
                                onChange={(t: UserType) => {
                                    const kindChanged = linkKind(t) !== linkKind(data.UserType);
                                    // داخلی ↔ مخاطب: مخاطبِ انتخاب‌شده می‌ماند؛ رفتن به/از طرف‌حساب: انتخاب پاک می‌شود
                                    setData((d) => ({ ...d, UserType: t, CrmPersonID: t === 'PARTY' ? null : d.CrmPersonID, CrmPartyID: t === 'PARTY' ? d.CrmPartyID : null }));
                                    setCrmLinkError(null);
                                    if (kindChanged) {
                                        setCrmOptions([]);
                                        loadCrmOptions(t, '');
                                    }
                                }}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label={data.UserType === 'PARTY' ? 'طرف‌حساب' : data.UserType === 'PERSON' ? 'فرد مرتبط' : 'فرد مرتبط (اختیاری، برایِ آدرس و تلفن)'}
                            required={data.UserType !== 'INTERNAL'}
                            validateStatus={crmLinkError ? 'error' : ''}
                            help={crmLinkError}
                        >
                            <Select
                                showSearch
                                allowClear
                                filterOption={false}
                                placeholder={data.UserType === 'PARTY' ? 'جست‌وجو و انتخابِ طرف‌حساب…' : 'جست‌وجو و انتخابِ فرد مرتبط…'}
                                value={(data.UserType === 'PARTY' ? data.CrmPartyID : data.CrmPersonID) ?? undefined}
                                onSearch={(s) => loadCrmOptions(data.UserType, s)}
                                onChange={(v) => {
                                    const id = v == null ? null : Number(v);
                                    setData((d) => (d.UserType === 'PARTY' ? { ...d, CrmPartyID: id } : { ...d, CrmPersonID: id }));
                                    setCrmLinkError(null);
                                }}
                                notFoundContent={crmLoading ? <Spin size="small" /> : 'موردی پیدا نشد'}
                                options={crmOptions.map((o) => {
                                    const takenByOther = o.linkedUserId !== null && o.linkedUserId !== editingUser?.UserID;
                                    return { value: o.id, label: takenByOther ? `${o.name} (متصل به ${o.linkedUserName})` : o.name, disabled: takenByOther };
                                })}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* توضیحات */}
                    <Col span={24}>
                        <Form.Item
                            label="توضیحات"
                            name="Description"
                            rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                            validateStatus={errors.Description ? 'error' : ''}
                            help={errors.Description}
                        >
                            <Input.TextArea
                                placeholder="توضیحات اختیاری..."
                                value={data.Description}
                                onChange={(e) => setData('Description', e.target.value)}
                                rows={3}
                                maxLength={500}
                                showCount
                            />
                        </Form.Item>
                    </Col>
                </Row>
            </Form>
        </Modal>
    );
}
