import { useEffect, useState } from 'react';
import { Alert, Space, Tag, Typography } from 'antd';
import { PaperClipOutlined } from '@ant-design/icons';
import { gregorianToJalaliDateTimeDisplay } from '../Utils/jalali';

interface RelatedAttachment {
    name: string;
    url: string;
    source: string;
    description?: string | null;
    occurrence?: string | null;
}

export default function RelatedAttachments({ kind, id }: { kind: 'event' | 'project' | 'message'; id: number }) {
    const [files, setFiles] = useState<RelatedAttachment[]>([]);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const request = new AbortController();
        setFiles([]);
        setFailed(false);
        fetch(`/calendar/related-attachments/${kind}/${id}`, {
            signal: request.signal, headers: { Accept: 'application/json' },
        }).then(async (response) => {
            if (!response.ok) throw new Error('Related attachments unavailable');
            const result = await response.json();
            if (!request.signal.aborted) setFiles(result);
        }).catch(() => {
            if (!request.signal.aborted) setFailed(true);
        });
        return () => request.abort();
    }, [kind, id]);

    if (failed) return <Alert type="warning" showIcon message="دریافت ضمیمه‌های مرتبط انجام نشد." />;
    if (!files.length) return null;

    return <section style={{ marginBlock: 12 }}>
        <Typography.Text strong>ضمیمه‌های مرتبط</Typography.Text>
        <Space direction="vertical" style={{ width: '100%', marginTop: 8 }}>
            {files.map((file) => <div key={file.url} style={{ overflowWrap: 'anywhere' }}>
                <Space wrap size={6}>
                    <PaperClipOutlined />
                    <a href={file.url} target="_blank" rel="noopener noreferrer">{file.name}</a>
                    <Tag>{file.source}</Tag>
                    {file.occurrence && <Typography.Text type="secondary">
                        نوبت {gregorianToJalaliDateTimeDisplay(file.occurrence)}
                    </Typography.Text>}
                </Space>
                {file.description && <div>{file.description}</div>}
            </div>)}
        </Space>
    </section>;
}
