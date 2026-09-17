import { useState } from 'react';
import { Card, Typography } from 'antd';
import { FileTextOutlined } from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import { STYLES } from '../../theme';
import MessageFromTemplateModal from '../../Components/Workflow/MessageFromTemplateModal';

const { Text } = Typography;

interface TargetUser {
    UserID: number;
    FullName: string;
}

/**
 * مسیرِ مستقلِ «ارسال از رویِ قالب» — از منویِ واقعیِ سیستم قابلِ‌دسترسی، بدونِ
 * نیاز به واردکردنِ URL مستقیم. همان MessageFromTemplateModalِ استفاده‌شده در
 * دکمهٔ «ارسال از رویِ الگو»یِ صفحهٔ Messages/Create را مستقیماً و از همان
 * لحظهٔ ورود باز می‌کند؛ «پیامِ جدید» (Messages/Create) کاملاً دست‌نخورده و
 * مسیرِ جداگانهٔ خودش را حفظ می‌کند.
 */
export default function MessageCreateFromTemplate() {
    const { targets } = usePage().props as unknown as { targets: TargetUser[] };

    const [modalOpen, setModalOpen] = useState(true);

    return (
        <MainLayout>
            <PageHeader
                icon={<FileTextOutlined />}
                title="ارسال از روی قالب"
                subtitle="انتخابِ فرایند و قالبِ نامه برایِ ساختِ یک پیامِ آماده"
                backHref="/messages"
                backLabel="بازگشت به کارتابل"
            />

            <Card style={STYLES.card}>
                <Text type="secondary">برایِ ادامه از پنجرهٔ باز‌شده، فرایند و قالبِ موردِنظر را انتخاب کنید.</Text>
            </Card>

            <MessageFromTemplateModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    router.visit('/messages');
                }}
                targets={targets || []}
                onSuccess={(_message, messageId) => {
                    setModalOpen(false);
                    router.visit(`/messages/${messageId}`);
                }}
            />
        </MainLayout>
    );
}
