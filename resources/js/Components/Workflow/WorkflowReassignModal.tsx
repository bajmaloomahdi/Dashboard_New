import { useEffect, useState } from 'react';
import { Modal, Select, Input, Alert, Space, Typography, Tag } from 'antd';
import { SendOutlined, SwapOutlined } from '@ant-design/icons';
import { wfApi } from './workflowApi';

const { Text } = Typography;

interface UserOption {
    UserID: number;
    FullName: string;
    UnitName?: string | null;
    IsManager?: boolean | number;
}

interface WorkflowReassignModalProps {
    open: boolean;
    mode: 'forward' | 'delegate';
    messageId: number;
    users: UserOption[];
    currentUserId: number;
    /** کاربرانی که هم‌اکنون انجام‌دهندهٔ فعالِ همین مرحله‌اند — فقط برای راهنماییِ فرم، سرور هم همین را قطعی بررسی می‌کند. */
    activeAssigneeIds: number[];
    onClose: () => void;
    /** پس از موفقیت — برایِ Reload کردنِ جزئیاتِ تسک در کامپوننتِ والد */
    onSuccess: (message: string) => void;
}

/**
 * Modalِ مشترکِ Forward و Delegate — هر دو دقیقاً همان شکلِ ورودی (کاربرِ مقصد +
 * توضیحِ اختیاری) و همان قراردادِ خروجی را دارند؛ فقط endpoint فرق می‌کند.
 */
export default function WorkflowReassignModal({
    open,
    mode,
    messageId,
    users,
    currentUserId,
    activeAssigneeIds,
    onClose,
    onSuccess,
}: WorkflowReassignModalProps) {
    const [targetUserId, setTargetUserId] = useState<number | null>(null);
    const [comment, setComment] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setTargetUserId(null);
            setComment('');
            setError(null);
        }
    }, [open]);

    const title = mode === 'forward' ? 'ارجاعِ تسک' : 'تفویضِ تسک';
    const icon = mode === 'forward' ? <SendOutlined /> : <SwapOutlined />;
    const endpoint = mode === 'forward' ? 'forward' : 'delegate';
    const helpText =
        mode === 'forward'
            ? 'تسک به‌طورِ کامل به کاربرِ انتخاب‌شده منتقل می‌شود.'
            : 'مسئولیتِ موقتِ تسک به کاربرِ انتخاب‌شده منتقل می‌شود؛ تا پیش از بازپس‌گیری، شما نمی‌توانید رویِ آن اقدام کنید.';

    // کاربرِ خودمان و کسانی که هم‌اکنون انجام‌دهندهٔ فعال‌اند از لیست کنار گذاشته می‌شوند
    // (صرفاً راهنماییِ فرم — تصمیمِ نهایی همیشه با سرور است)
    const options = users
        .filter((u) => Number(u.UserID) !== Number(currentUserId) && !activeAssigneeIds.includes(Number(u.UserID)))
        .map((u) => ({
            value: u.UserID,
            label: (
                <Space>
                    <Text>{u.FullName}</Text>
                    {u.IsManager === 1 || u.IsManager === true ? (
                        <Tag color="gold" style={{ borderRadius: 6, marginInlineEnd: 0 }}>مدیر</Tag>
                    ) : null}
                    {u.UnitName ? <Text type="secondary" style={{ fontSize: 12 }}>{u.UnitName}</Text> : null}
                </Space>
            ),
        }));

    const handleSubmit = async () => {
        if (!targetUserId) {
            setError('لطفاً کاربرِ مقصد را انتخاب کنید.');
            return;
        }

        setSubmitting(true);
        setError(null);

        const res = await wfApi(`/workflow/messages/${messageId}/${endpoint}`, 'POST', {
            targetUserId,
            comment: comment.trim() || undefined,
        });

        setSubmitting(false);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }

        onSuccess(res.message);
    };

    return (
        <Modal
            open={open}
            title={<Space>{icon}<span>{title}</span></Space>}
            onCancel={submitting ? undefined : onClose}
            onOk={handleSubmit}
            okText={mode === 'forward' ? 'ارجاع' : 'تفویض'}
            cancelText="انصراف"
            confirmLoading={submitting}
            destroyOnClose
        >
            <Space direction="vertical" size={14} style={{ width: '100%' }}>
                <Text type="secondary">{helpText}</Text>

                {error ? <Alert type="error" message={error} showIcon /> : null}

                <div>
                    <Text style={{ display: 'block', marginBottom: 6 }}>کاربرِ مقصد</Text>
                    <Select
                        style={{ width: '100%' }}
                        size="large"
                        placeholder="جستجو و انتخابِ کاربر..."
                        value={targetUserId ?? undefined}
                        onChange={(v: number) => setTargetUserId(v)}
                        options={options}
                        optionFilterProp="label"
                        showSearch
                    />
                </div>

                <div>
                    <Text style={{ display: 'block', marginBottom: 6 }}>توضیح (اختیاری)</Text>
                    <Input.TextArea
                        rows={3}
                        placeholder="دلیل ارجاع/تفویض..."
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        maxLength={1000}
                        showCount
                    />
                </div>
            </Space>
        </Modal>
    );
}
