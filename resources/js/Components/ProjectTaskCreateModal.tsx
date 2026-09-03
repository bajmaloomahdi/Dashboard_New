import { useEffect, useState } from 'react';
import { Modal, Input, Select, Button, Typography, Space, Alert } from 'antd';
import { ThunderboltOutlined } from '@ant-design/icons';
import NotificationModal, { NotificationType } from './NotificationModal';
import { getUserDisplayName } from '../Utils/userHelpers';

interface Member {
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
}

interface PriorityOption {
    msgPriorityID: number;
    Name: string;
}

interface ProjectTaskCreateModalProps {
    open: boolean;
    onClose: (created: boolean) => void;
    projectId: number;
    projectTitle: string;
    member: Member | null; // عضوی که دکمه‌ی «ایجاد وظیفه» برایش زده شده
    priorities: PriorityOption[];
}

/** خواندن توکن CSRF از کوکی */
function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function ProjectTaskCreateModal({
    open,
    onClose,
    projectId,
    projectTitle,
    member,
    priorities,
}: ProjectTaskCreateModalProps) {
    const [text, setText] = useState('');
    const [priorityId, setPriorityId] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });

    useEffect(() => {
        if (open) {
            setText('');
            setPriorityId(null);
        }
    }, [open, member]);

    const showNotification = (type: NotificationType, message: string) =>
        setNotification({ open: true, type, message });

    const handleSubmit = async () => {
        if (!member) return;
        if (!text.trim()) {
            showNotification('warning', 'متن وظیفه را وارد کنید');
            return;
        }
        if (!priorityId) {
            showNotification('warning', 'اولویت پیام را انتخاب کنید');
            return;
        }

        setSaving(true);
        try {
            const res = await fetch(`/projects/${projectId}/tasks`, {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': getXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ToUserID: member.UserID, msgPriorityID: priorityId, MessageText: text }),
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) {
                showNotification('success', data.message || 'وظیفه با موفقیت ایجاد شد.');
                onClose(true);
            } else {
                showNotification('error', data.message || 'خطا در ایجاد وظیفه');
            }
        } catch {
            showNotification('error', 'خطا در ارتباط با سرور');
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Modal
                title={
                    <Space>
                        <ThunderboltOutlined style={{ color: '#D97706' }} />
                        <span>ایجاد وظیفه برای {member ? getUserDisplayName(member) : ''}</span>
                    </Space>
                }
                open={open}
                onCancel={() => onClose(false)}
                className="responsive-modal"
                footer={[
                    <Button key="cancel" onClick={() => onClose(false)}>
                        انصراف
                    </Button>,
                    <Button key="submit" type="primary" loading={saving} onClick={handleSubmit}>
                        ایجاد وظیفه
                    </Button>,
                ]}
            >
                <Space direction="vertical" style={{ width: '100%' }} size="middle">
                    <div>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            پروژه
                        </Typography.Text>
                        <div>
                            <Typography.Text strong>{projectTitle}</Typography.Text>
                        </div>
                    </div>

                    {member?.PositionTitle ? (
                        <Alert
                            type="info"
                            showIcon
                            style={{ borderRadius: 8 }}
                            message={`اگر ${getUserDisplayName(member)} مدیر واحد نباشد، به‌صورت خودکار برای مدیر واحدش هم رونوشت ثبت می‌شود.`}
                        />
                    ) : null}

                    <div>
                        <Typography.Text strong>
                            اولویت پیام <span style={{ color: '#EF4444' }}>*</span>
                        </Typography.Text>
                        <Select
                            style={{ width: '100%', marginTop: 6 }}
                            placeholder="انتخاب اولویت..."
                            value={priorityId}
                            onChange={(v) => setPriorityId(v)}
                            options={(priorities || []).map((p) => ({ value: p.msgPriorityID, label: p.Name }))}
                        />
                    </div>

                    <div>
                        <Typography.Text strong>متن وظیفه</Typography.Text>
                        <Input.TextArea
                            style={{ marginTop: 6 }}
                            rows={5}
                            placeholder="توضیح وظیفه را بنویسید..."
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            showCount
                            maxLength={5000}
                        />
                    </div>
                </Space>
            </Modal>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />
        </>
    );
}