import { useEffect, useState } from 'react';
import { Card, Avatar, Typography, Space, Button, Input, Upload, List, Empty, Modal, Image } from 'antd';
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
import { normalizeForSearch } from '../Utils/persianSearch';
import NotificationModal, { NotificationType } from './NotificationModal';
import RelatedAttachments from './RelatedAttachments';
import type { UploadFile } from 'antd/es/upload/interface';

const { Text, Paragraph } = Typography;

interface ProjectComment {
    ProjectCommentID: number;
    UserID: number;
    FullName: string;
    Comment: string;
    CreateDate: string;
    EditedAt?: string | null;
    EditedByName?: string | null;
}

interface ProjectAttachment {
    ProjectAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number;
    FilePath: string;
    CreateDate: string;
    CreateUserName: string | null;
    CreateUser?: number | null;
    Description?: string | null;
    EditedAt?: string | null;
    EditedByName?: string | null;
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

function isImageAttachment(file: ProjectAttachment): boolean {
    const extension = (file.FileExtension || file.FileName.split('.').pop() || '').replace(/^\./, '');
    return /^(png|jpe?g|gif|webp|bmp|avif|ico)$/i.test(extension);
}

interface ProjectCommentsProps {
    projectId: number;
    currentUserId: number;
    onCount?: (count: number) => void;
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

export default function ProjectComments({ projectId, currentUserId, onCount }: ProjectCommentsProps) {
    const [edit, setEdit] = useState<{ kind: TimelineItem['kind']; id: number; text: string } | null>(null);
    const [editing, setEditing] = useState(false);
    const [editError, setEditError] = useState('');
    const [comments, setComments] = useState<ProjectComment[]>([]);
    const [attachments, setAttachments] = useState<ProjectAttachment[]>([]);
    const [loading, setLoading] = useState(false);
    const [text, setText] = useState('');
    const [search, setSearch] = useState('');
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
            if (res.ok) onCount?.((data.comments?.length ?? 0) + (data.attachments?.length ?? 0));
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

    // نظرها و ضمیمه‌ها در یک فهرستِ زمانی: تازه‌ترین بالا، قدیمی‌ترین پایین؛
    // در زمانِ یکسان (نظر و ضمیمه با هم ثبت شده‌اند) اول نظر، بعد ضمیمه‌هایش
    const timeline: TimelineItem[] = [
        ...comments.map((c) => ({ kind: 'comment' as const, key: `c-${c.ProjectCommentID}`, at: toTime(c.CreateDate), id: Number(c.ProjectCommentID), comment: c })),
        ...attachments.map((a) => ({ kind: 'file' as const, key: `f-${a.ProjectAttachmentID}`, at: toTime(a.CreateDate), id: Number(a.ProjectAttachmentID), file: a })),
    ].sort((x, y) => y.at - x.at || (x.kind === y.kind ? 0 : x.kind === 'comment' ? -1 : 1) || y.id - x.id);

    const searchTerms = normalizeForSearch(search).trim().split(/\s+/).filter(Boolean);
    const matchingItems = searchTerms.length ? timeline.filter((item) => {
        const content = item.kind === 'comment' ? item.comment.Comment : `${item.file.FileName} ${item.file.Description ?? ''}`;
        const normalized = normalizeForSearch(content);
        return searchTerms.every((term) => normalized.includes(term));
    }) : timeline;

    const saveEdit = async () => {
        if (!edit || editing) return;
        setEditing(true);
        setEditError('');
        try {
            const res = await fetch(`/projects/${projectId}/activity/${edit.kind}/${edit.id}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getXsrfToken() },
                body: JSON.stringify({ text: edit.text.trim() || null }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'ذخیره انجام نشد.');
            setEdit(null);
            await load();
        } catch (error) {
            setEditError(error instanceof Error ? error.message : 'ذخیره انجام نشد.');
        } finally {
            setEditing(false);
        }
    };

    const editButton = (item: TimelineItem) => {
        const owner = item.kind === 'comment' ? item.comment.UserID : item.file.CreateUser;
        return currentUserId > 0 && Number(owner) === currentUserId ? <Button size="small" type="link" onClick={() => {
            setEditError('');
            setEdit({ kind: item.kind, id: item.id, text: item.kind === 'comment' ? item.comment.Comment : item.file.Description ?? '' });
        }}>{item.kind === 'file' && !item.file.Description ? 'افزودن توضیح' : 'ویرایش'}</Button> : null;
    };

    const editedLabel = (item: ProjectComment | ProjectAttachment) => item.EditedAt ? <Text type="secondary">
        ویرایش‌شده{item.EditedByName ? ` توسط ${item.EditedByName}` : ''} — {gregorianToJalaliDateTimeDisplay(item.EditedAt)}
    </Text> : null;

    const hasComment = text.trim() !== '';
    const hasFiles = files.length > 0;
    const submitLabel = hasFiles && !hasComment
        ? 'ثبت ضمیمه'
        : hasComment && !hasFiles
        ? 'ثبت توضیحات'
        : 'ثبت';

    return (
        <>
            <Modal open={Boolean(edit)} title={edit?.kind === 'file' ? 'توضیح ضمیمه' : 'ویرایش نظر'}
                okText="ذخیره" cancelText="انصراف" confirmLoading={editing}
                okButtonProps={{ disabled: edit?.kind === 'comment' && !edit.text.trim() }}
                onOk={saveEdit} onCancel={() => { if (!editing) setEdit(null); }}>
                <Input.TextArea aria-label="متن" rows={3} value={edit?.text ?? ''} disabled={editing}
                    maxLength={edit?.kind === 'file' ? 500 : 3000} onChange={(event) => {
                        const text = event.target.value;
                        setEdit((current) => current ? { ...current, text } : current);
                    }} />
                {editError && <Text type="danger" role="alert">{editError}</Text>}
            </Modal>
            <Card
                style={STYLES.card}
                title={
                    <>
                        <CommentOutlined /> نظرات و ضمیمه‌های پروژه ({comments.length} نظر، {attachments.length} ضمیمه)
                    </>
                }
            >
                <div style={{ borderBottom: `1px solid ${THEME.borderLight}`, marginBottom: 16, paddingBottom: 12 }}>
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
                <Input.Search allowClear value={search} onChange={(event) => setSearch(event.target.value)}
                    aria-label="جست‌وجوی نظرات و ضمیمه‌ها" placeholder="جست‌وجو در متن نظرات، نام فایل و توضیح ضمیمه"
                    style={{ marginBottom: 12 }} />
                {([
                    { key: 'comments', title: 'نظرات', items: matchingItems.filter((item) => item.kind === 'comment') },
                    { key: 'images', title: 'تصاویر پروژه', items: matchingItems.filter((item) => item.kind === 'file' && isImageAttachment(item.file)) },
                    { key: 'files', title: 'فایل‌های پیوست', items: matchingItems.filter((item) => item.kind === 'file' && !isImageAttachment(item.file)) },
                ]).map((section) => <List
                    key={section.key}
                    header={<Text strong>{section.title} ({section.items.length})</Text>}
                    loading={loading}
                    dataSource={section.items}
                    rowKey="key"
                    locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={searchTerms.length ? 'نتیجه‌ای پیدا نشد' : 'موردی ثبت نشده است'} /> }}
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
                                        {editedLabel(item.comment)} {editButton(item)}
                                    </div>
                                </div>
                            </List.Item>
                        ) : (
                            <List.Item style={{ border: 'none', padding: '6px 0' }}>
                                <div className="project-comment-bubble project-attachment-bubble">
                                {isImageAttachment(item.file) && <Image
                                    width={64} height={64} style={{ objectFit: 'cover', borderRadius: 6 }}
                                    src={`/projects/${projectId}/attachments/${item.file.ProjectAttachmentID}`}
                                    alt={item.file.FileName} />}
                                <a
                                    href={`/projects/${projectId}/attachments/${item.file.ProjectAttachmentID}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
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
                                <div style={{ minWidth: 0 }}>
                                    {item.file.Description && <Paragraph style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{item.file.Description}</Paragraph>}
                                    {editedLabel(item.file)} {editButton(item)}
                                </div>
                                </div>
                            </List.Item>
                        )
                    }
                />)}

                <RelatedAttachments kind="project" id={projectId} />
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
