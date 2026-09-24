import { useEffect, useState } from 'react';
import { Modal, Form, Input, Select, Row, Col, Button, Alert } from 'antd';
import { SaveOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import PersianDateInput from '../../../Components/PersianDateInput';

export interface Person {
    PersonID: number;
    FirstName: string;
    LastName: string;
    TitleID: number | null;
    IdentifierNumber: string | null;
    IdentifierDate: string | null;
    Description: string | null;
    IsActive: boolean | number | string;
}

interface PersonFormModalProps {
    open: boolean;
    onClose: () => void;
    editingPerson: Person | null;
    titles: { TitleID: number; DisplayName: string }[];
    onSuccess: (message: string) => void;
}

const emptyValues = {
    titleId: null as number | null,
    firstName: '',
    lastName: '',
    identifierNumber: '',
    identifierDate: null as string | null,
    description: '',
};

/**
 * فرمِ ایجاد/ویرایشِ مخاطب (CrmPersons) — شخصِ حقیقیِ مستقل از طرف‌حساب که از
 * طریقِ رابطه (سمت/نقش/مخاطبِ اصلی) به یک یا چند طرف‌حساب متصل می‌شود.
 * کدِ ملی و تاریخِ تولد اختیاری‌اند.
 */
export default function PersonFormModal({ open, onClose, editingPerson, titles, onSuccess }: PersonFormModalProps) {
    const isEdit = !!editingPerson;
    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;

        const initial = editingPerson
            ? {
                  titleId: editingPerson.TitleID,
                  firstName: editingPerson.FirstName || '',
                  lastName: editingPerson.LastName || '',
                  identifierNumber: editingPerson.IdentifierNumber || '',
                  identifierDate: editingPerson.IdentifierDate,
                  description: editingPerson.Description || '',
              }
            : emptyValues;

        setValues(initial);
        setFormError(null);
        setFieldErrors({});
    }, [open, editingPerson]);

    const handleClose = () => {
        if (processing) return;
        onClose();
    };

    const handleSubmit = async () => {
        if (!values.firstName.trim() || !values.lastName.trim()) {
            setFormError('نام و نامِ خانوادگی الزامی است.');
            return;
        }

        setProcessing(true);
        setFormError(null);
        setFieldErrors({});

        const res = await crmApi('/crm/persons', 'POST', {
            personId: editingPerson ? editingPerson.PersonID : undefined,
            firstName: values.firstName.trim(),
            lastName: values.lastName.trim(),
            titleId: values.titleId ?? undefined,
            identifierNumber: values.identifierNumber.trim() || undefined,
            identifierDate: values.identifierDate || undefined,
            description: values.description.trim() || undefined,
        });

        setProcessing(false);

        if (!res.ok || !res.success) {
            if (res.errors && typeof res.errors === 'object' && !Array.isArray(res.errors)) {
                const mapped: Record<string, string> = {};
                Object.entries(res.errors as Record<string, string[]>).forEach(([field, messages]) => {
                    mapped[field] = Array.isArray(messages) ? messages[0] : String(messages);
                });
                setFieldErrors(mapped);
            }
            setFormError(res.message);
            return;
        }

        onSuccess(res.message);
    };

    return (
        <Modal
            title={isEdit ? 'ویرایشِ مخاطب' : 'مخاطبِ جدید'}
            open={open}
            onCancel={handleClose}
            width={600}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button key="submit" type="primary" icon={<SaveOutlined />} loading={processing} onClick={handleSubmit}>
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ مخاطب'}
                </Button>,
            ]}
            destroyOnClose
        >
            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={6}>
                        <Form.Item label="عنوان">
                            <Select
                                allowClear
                                placeholder="عنوان"
                                value={values.titleId ?? undefined}
                                onChange={(v) => setValues((s) => ({ ...s, titleId: v ?? null }))}
                                options={(titles || []).map((t) => ({ value: t.TitleID, label: t.DisplayName }))}
                                size="large"
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={9}>
                        <Form.Item label="نام" required validateStatus={fieldErrors.firstName ? 'error' : ''} help={fieldErrors.firstName}>
                            <Input value={values.firstName} onChange={(e) => setValues((s) => ({ ...s, firstName: e.target.value }))} size="large" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={9}>
                        <Form.Item label="نامِ خانوادگی" required validateStatus={fieldErrors.lastName ? 'error' : ''} help={fieldErrors.lastName}>
                            <Input value={values.lastName} onChange={(e) => setValues((s) => ({ ...s, lastName: e.target.value }))} size="large" />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item label="کدِ ملی" validateStatus={fieldErrors.identifierNumber ? 'error' : ''} help={fieldErrors.identifierNumber}>
                            <Input
                                dir="ltr"
                                maxLength={10}
                                value={values.identifierNumber}
                                onChange={(e) => setValues((s) => ({ ...s, identifierNumber: e.target.value.replace(/\D/g, '') }))}
                                size="large"
                                placeholder="۱۰ رقم"
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={12}>
                        <Form.Item label="تاریخِ تولد">
                            <PersianDateInput value={values.identifierDate} onChange={(v) => setValues((s) => ({ ...s, identifierDate: v }))} />
                        </Form.Item>
                    </Col>
                </Row>

                <Form.Item label="توضیحات">
                    <Input.TextArea value={values.description} onChange={(e) => setValues((s) => ({ ...s, description: e.target.value }))} rows={3} maxLength={2000} showCount />
                </Form.Item>
            </Form>
        </Modal>
    );
}
