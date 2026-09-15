import { useEffect, useState } from 'react';
import { Modal, Radio, Space, Typography, Empty, Spin, Alert, Button } from 'antd';
import { PlayCircleOutlined } from '@ant-design/icons';
import { wfApi } from './workflowApi';

const { Text } = Typography;

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
    ActiveVersionNo: number | null;
}

interface StartWorkflowModalProps {
    open: boolean;
    onClose: () => void;
    entityType: string;
    entityId: number;
    /** بعد از Startِ موفق — والد پیامِ موفقیت را نشان می‌دهد و صفحه را Reload می‌کند. */
    onSuccess: (message: string) => void;
}

/**
 * Message Detail → «شروع فرایند» → این Modal.
 *
 * فیلترِ EntityType/ActiveVersionNo اینجا فقط UX است (کوتاه‌کردنِ لیست برایِ
 * کاربر)؛ Backendِ POST /workflow/instances مستقل و قطعی همان موارد را (و
 * موارد بیشتری مثلِ دسترسیِ کاربر به Message) دوباره Validate می‌کند.
 */
export default function StartWorkflowModal({ open, onClose, entityType, entityId, onSuccess }: StartWorkflowModalProps) {
    const [loading, setLoading] = useState(false);
    const [definitions, setDefinitions] = useState<WfDefinition[]>([]);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;

        setError(null);
        setSelectedId(null);
        setLoading(true);

        wfApi('/workflow/definitions?isActive=1').then((res) => {
            setLoading(false);
            if (!res.ok || !res.success) {
                setError(res.message || 'خطا در دریافتِ فهرستِ فرایندها.');
                return;
            }
            const items: WfDefinition[] = (res.items || []).filter(
                (d: WfDefinition) => d.EntityType === entityType && d.ActiveVersionNo != null
            );
            setDefinitions(items);
        });
    }, [open, entityType]);

    const handleStart = async () => {
        if (!selectedId) return;

        setStarting(true);
        setError(null);

        const res = await wfApi('/workflow/instances', 'POST', {
            definitionId: selectedId,
            entityType,
            entityId,
        });

        setStarting(false);

        if (!res.ok || !res.success) {
            setError(res.message || 'شروعِ فرایند با خطا مواجه شد.');
            return;
        }

        onSuccess(res.message || 'فرایند آغاز شد.');
    };

    return (
        <Modal
            open={open}
            title={
                <Space>
                    <PlayCircleOutlined />
                    <span>شروعِ فرایند</span>
                </Space>
            }
            onCancel={() => (starting ? undefined : onClose())}
            footer={[
                <Button key="cancel" onClick={onClose} disabled={starting}>
                    انصراف
                </Button>,
                <Button
                    key="start"
                    type="primary"
                    icon={<PlayCircleOutlined />}
                    loading={starting}
                    disabled={!selectedId || loading || definitions.length === 0}
                    onClick={handleStart}
                >
                    شروع
                </Button>,
            ]}
        >
            {error ? (
                <Alert type="error" showIcon message={error} style={{ marginBottom: 14, borderRadius: 8 }} />
            ) : null}

            {loading ? (
                <div style={{ textAlign: 'center', padding: '24px 0' }}>
                    <Spin />
                </div>
            ) : definitions.length === 0 ? (
                <Empty description="فرایندِ فعالی برایِ این نوع مورد تعریف نشده است." />
            ) : (
                <Radio.Group
                    style={{ width: '100%' }}
                    value={selectedId}
                    onChange={(e) => setSelectedId(e.target.value)}
                >
                    <Space direction="vertical" style={{ width: '100%' }}>
                        {definitions.map((d) => (
                            <Radio key={d.DefinitionID} value={d.DefinitionID} style={{ width: '100%' }}>
                                <Text strong>{d.Name}</Text>{' '}
                                <Text type="secondary" style={{ fontSize: 12 }}>
                                    ({d.Code})
                                </Text>
                            </Radio>
                        ))}
                    </Space>
                </Radio.Group>
            )}
        </Modal>
    );
}
