import { useEffect, useState } from 'react';
import { Card, Avatar, Typography, Space, Button, Input, Upload, List, Empty } from 'antd';
import {
    CommentOutlined,
    UserOutlined,
    PaperClipOutlined,
    SendOutlined,
    UploadOutlined,
    FileOutlined,
    EyeOutlined,
} from '@ant-design/icons';
import { THEME, STYLES } from '../theme';
import { gregorianToJalaliDateTimeDisplay } from '../Utils/jalali';
import NotificationModal, { NotificationType } from './NotificationModal';
import type { UploadFile } from 'antd/es/upload/interface';

const { Text, Paragraph } = Typography;

interface ProjectComment {
    ProjectCommentID: number;
    UserID: number;
    FullName: string;
    Comment: string;
    CreateDate: string;
}

interface ProjectAttachment {
    ProjectAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number;
    FilePath: string;
    CreateDate: string;
    CreateUserName: string | null;
}

/** یک ردیفِ فهرستِ زمانی: نظر یا ضمیمه */
type TimelineItem =
    | { kind: 'comment'; key: string; at: number; id: number; comment: ProjectComment }
    | { kind: 'file'; key: string; at: number; id: number; file: ProjectAttachment };

/** «2026-10-28 10:46:00.000» → میلی‌ثانیه (برایِ مرتب‌سازی) */
function toTime(v: string): number {
    const t = Date.parse(String(v || '').replace(' ', 'T'));
    return Number.isNaN(t) ? 0 : t;
}

interface ProjectCommentsProps {
    projectId: number;
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function formatFileSize(bytes: number): string {
    if (!bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let i = 0;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(1)} ${units[i]}`;
}

export default function ProjectComments({ projectId }: ProjectCommentsProps) {
    const [comments, setComments] = useState<ProjectComment[]>([]);
    const [attachments, setAttachments] = useState<ProjectAttachment[]>([]);
    const [loading, setLoading] = useState(false);
    const [text, setText] = useState('');
    const [files, setFiles] = useState<UploadFile[]>([]);
    const [saving, setSaving] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });

    const showNotification = (type: NotificationType, message: string) =>
        setNotification({ open: true, type, message });

    const load = async () => {
        setLoading(true);
        try {
            const res = await fetch(`/projects/${projectId}/comments`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setComments(data.comments || []);
            setAttachments(data.attachments || []);
        } catch {
            // بی‌صدا نادیده گرفته می‌شود
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId]);

    const handleSubmit = async () => {
        if (!text.trim() && files.length === 0) {
            showNotification('warning', 'نظر یا فایل ضمیمه را وارد کنید');
            return;
        }
        setSaving(true);
        try {
            const formData = new FormData();
            if (text.trim()) formData.append('Comment', text);
            files.forEach((f) => {
                if (f.originFileObj) formData.append('attachments[]', f.originFileObj as File);
            });

            const res = await fetch(`/projects/${projectId}/comments`, {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': getXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: formData,
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) {
                showNotification('success', data.message || 'با موفقیت ثبت شد.');
                setText('');
                setFiles([]);
                load();
            } else {
                showNotification('error', data.message || 'خطا در ثبت');
            }
        } catch {
            showNotification('error', 'خطا در ارتباط با سرور');
        } finally {
            setSaving(false);
        }
    };

    // نظرها و ضمیمه‌ها در یک فهرستِ زمانی: قدیمی‌ترین بالا، تازه‌ترین پایین (کنارِ کادرِ ثبت)؛
    // در زمانِ یکسان (نظر و ضمیمه با هم ثبت شده‌اند) اول نظر، بعد ضمیمه‌هایش
    const timeline: TimelineItem[] = [
        ...comments.map((c) => ({ kind: 'comment' as const, key: `c-${c.ProjectCommentID}`, at: toTime(c.CreateDate), id: Number(c.ProjectCommentID), comment: c })),
        ...attachments.map((a) => ({ kind: 'file' as const, key: `f-${a.ProjectAttachmentID}`, at: toTime(a.CreateDate), id: Number(a.ProjectAttachmentID), file: a })),
    ].sort((x, y) => x.at - y.at || (x.kind === y.kind ? 0 : x.kind === 'comment' ? -1 : 1) || x.id - y.id);

    const hasComment = text.trim() !== '';
    const hasFiles = files.length > 0;
    const submitLabel = hasFiles && !hasComment
        ? 'ثبت ضمیمه'
        : hasComment && !hasFiles
        ? 'ثبت توضیحات'
        : 'ثبت';

    return (
        <>
            <Card
                style={STYLES.card}
                title={
                    <>
                        <CommentOutlined /> نظرات و ضمیمه‌های پروژه ({comments.length} نظر، {attachments.length} ضمیمه)
                    </>
                }
            >
                <List
                    loading={loading}
                    dataSource={timeline}
                    rowKey="key"
                    locale={{ emptyText: <Empty description="هنوز نظر یا ضمیمه‌ای ثبت نشده است" /> }}
                    renderItem={(item) =>
                        item.kind === 'comment' ? (
                            <List.Item style={{ border: 'none', padding: '6px 0' }}>
                                <div className="project-comment-bubble">
                                    <Avatar icon={<UserOutlined />} style={{ background: THEME.primaryGradient, flexShrink: 0 }} size={38} />
                                    <div style={{ flex: 1 }}>
                                        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 4 }}>
                                            <Text strong style={{ color: THEME.textPrimary }}>{item.comment.FullName}</Text>
                                            <Text type="secondary" style={{ fontSize: 12 }}>
                                                {gregorianToJalaliDateTimeDisplay(item.comment.CreateDate)}
                                            </Text>
                                        </div>
                                        <Paragraph style={{ margin: 0, whiteSpace: 'pre-wrap', color: THEME.textPrimary }}>{item.comment.Comment}</Paragraph>
                                    </div>
                                </div>
                            </List.Item>
                        ) : (
                            <List.Item style={{ border: 'none', padding: '6px 0' }}>
                                <a
                                    href={`/projects/${projectId}/attachments/${item.file.ProjectAttachmentID}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="project-comment-bubble project-attachment-bubble"
                                    style={{ color: 'inherit' }}
                                >
                                    <Avatar icon={<PaperClipOutlined />} style={{ background: THEME.bgHover, color: THEME.primary, flexShrink: 0 }} size={38} />
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 4 }}>
                                            <Text strong style={{ color: THEME.textPrimary }}>{item.file.CreateUserName || '—'}</Text>
                                            <Text type="secondary" style={{ fontSize: 12 }}>
                                                {gregorianToJalaliDateTimeDisplay(item.file.CreateDate)}
                                            </Text>
                                        </div>
                                        <Space size={6} style={{ maxWidth: '100%' }}>
                                            <FileOutlined style={{ color: THEME.primary }} />
                                            <Text ellipsis style={{ maxWidth: 420 }}>{item.file.FileName}</Text>
                                            <Text type="secondary" style={{ fontSize: 12 }}>({formatFileSize(item.file.FileSize)})</Text>
                                        </Space>
                                    </div>
                                    <EyeOutlined style={{ color: THEME.info, alignSelf: 'center' }} />
                                </a>
                            </List.Item>
                        )
                    }
                />

                <div style={{ borderTop: `1px solid ${THEME.borderLight}`, marginTop: 12, paddingTop: 12 }}>
                    <Input.TextArea
                        rows={3}
                        placeholder="نظر خود را بنویسید..."
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        maxLength={3000}
                        showCount
                    />
                    <Space style={{ marginTop: 8, width: '100%', justifyContent: 'space-between' }}>
                        <Upload
                            multiple
                            fileList={files}
                            beforeUpload={() => false}
                            onChange={({ fileList }) => setFiles(fileList)}
                        >
                            <Button icon={<UploadOutlined />}>افزودن ضمیمه</Button>
                        </Upload>
                        <Button type="primary" icon={<SendOutlined />} loading={saving} style={STYLES.primaryButton} onClick={handleSubmit}>
                            {submitLabel}
                        </Button>
                    </Space>
                </div>
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />

            <style>{`
                .project-comment-bubble {
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    width: 100%;
                    background: ${THEME.bgLighter};
                    border: 1px solid ${THEME.borderLight};
                    border-radius: 12px;
                    padding: 12px 14px;
                    transition: box-shadow 0.15s ease, border-color 0.15s ease;
                }
                .project-attachment-bubble {
                    border-style: dashed;
                    text-decoration: none;
                }
                .project-comment-bubble:hover {
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                    border-color: ${THEME.borderPrimary};
                }
            `}</style>
        </>
    );
}