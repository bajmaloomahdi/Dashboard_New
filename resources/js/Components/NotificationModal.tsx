import { useEffect } from 'react';
import { Modal, Typography } from 'antd';
import {
    CheckCircleFilled,
    CloseCircleFilled,
    InfoCircleFilled,
    WarningFilled,
} from '@ant-design/icons';

const { Text } = Typography;

export type NotificationType = 'success' | 'error' | 'info' | 'warning';

interface NotificationModalProps {
    open: boolean;
    type: NotificationType;
    message: string;
    duration?: number; // میلی‌ثانیه
    onClose: () => void;
}

const iconMap = {
    success: { icon: CheckCircleFilled, color: '#52c41a' },
    error: { icon: CloseCircleFilled, color: '#ff4d4f' },
    info: { icon: InfoCircleFilled, color: '#1890ff' },
    warning: { icon: WarningFilled, color: '#faad14' },
};

export default function NotificationModal({
    open,
    type,
    message,
    duration = 3000,
    onClose,
}: NotificationModalProps) {
    const { icon: Icon, color } = iconMap[type];

    // بسته شدن خودکار
    useEffect(() => {
        if (open) {
            const timer = setTimeout(() => {
                onClose();
            }, duration);

            return () => clearTimeout(timer);
        }
    }, [open, duration, onClose]);

    return (
        <Modal
            open={open}
            footer={null}
            closable={false}
            centered
            width={400}
            maskClosable={false}
            styles={{
                body: {
                    padding: '40px 24px',
                    textAlign: 'center',
                },
            }}
        >
            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                }}
                className="notification-modal-content"
            >
                <Icon
                    style={{
                        fontSize: 64,
                        color: color,
                        animation: 'popIn 0.4s ease-out',
                    }}
                />
                <div style={{ marginTop: 20 }}>
                    <Text style={{ fontSize: 16, color: '#262626', fontWeight: 500 }}>
                        {message}
                    </Text>
                </div>
            </div>

            <style>{`
                @keyframes popIn {
                    0% {
                        transform: scale(0);
                        opacity: 0;
                    }
                    50% {
                        transform: scale(1.2);
                    }
                    100% {
                        transform: scale(1);
                        opacity: 1;
                    }
                }
            `}</style>
        </Modal>
    );
}