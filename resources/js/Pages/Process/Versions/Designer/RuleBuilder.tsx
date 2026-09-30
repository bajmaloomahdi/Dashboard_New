import { Button, Select, Input, InputNumber, Space, Typography, Tag, Radio, Alert, TimePicker } from 'antd';
import { PlusOutlined, DeleteOutlined, ApartmentOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import PersianDateInput from '../../../../Components/PersianDateInput';
import { THEME, columnHelpers } from '../../../../theme';
import type { RuleCondition, RuleGroup, RuleNode, RuleOperator } from '../versionEditor';
import {
    type ConditionField,
    DECIMAL_PATTERN,
    emptyCondition,
    emptyGroup,
    isListOperator,
    isUnaryOperator,
    operatorOptions,
    parseAllowedValues,
    summarizeRule,
} from './ruleTypes';

const { Text } = Typography;

const MAX_UI_DEPTH = 5;

function toBoolField(v: unknown): boolean {
    if (typeof v === 'boolean') return v;
    if (typeof v === 'number') return v === 1;
    if (typeof v === 'string') return v === '1' || v.toLowerCase() === 'true';
    return false;
}

interface RuleBuilderProps {
    /** همهٔ فیلدهایِ این Definition (شاملِ غیرفعال — برایِ نمایشِ صحیحِ Ruleِ موجود لازم است) */
    fields: ConditionField[];
    value: RuleGroup | null;
    onChange: (rule: RuleGroup | null) => void;
    readOnly: boolean;
}

/**
 * Rule Builderِ واقعی برایِ Transitionهایِ خروجیِ Stepِ CONDITION — تولیدِ دقیقِ
 * RuleJsonِ سازگار با Contractِ Backend (ConditionRuleValidator/ConditionEvaluator).
 * هیچ eval/SQL Dynamic در کار نیست؛ فقط ساختِ درختِ GROUP/CONDITION.
 */
export default function RuleBuilder({ fields, value, onChange, readOnly }: RuleBuilderProps) {
    const activeFields = fields.filter((f) => toBoolField(f.IsActive));
    const fieldsByCode = new Map(fields.map((f) => [f.Code, f]));

    if (!value) {
        return (
            <Space direction="vertical" style={{ width: '100%' }} size={8}>
                <Text type="secondary" style={{ fontSize: 12 }}>این گذار شرطی ندارد.</Text>
                {!readOnly && (
                    <Button
                        size="small"
                        icon={<PlusOutlined />}
                        disabled={activeFields.length === 0}
                        onClick={() => onChange({ ...emptyGroup('AND'), children: [firstCondition(activeFields)] })}
                    >
                        افزودنِ شرط
                    </Button>
                )}
                {activeFields.length === 0 && (
                    <Text type="warning" style={{ fontSize: 12 }}>
                        هیچ فیلدِ شرطِ فعالی در Registry ثبت نشده — ابتدا از صفحهٔ «فیلدهایِ شروط» (/process/condition-fields) یک فیلد بسازید.
                    </Text>
                )}
            </Space>
        );
    }

    return (
        <Space direction="vertical" style={{ width: '100%' }} size={10}>
            <Alert
                type="info"
                showIcon
                icon={<ApartmentOutlined />}
                message={<Text style={{ fontSize: 12 }}>{summarizeRule(value, fieldsByCode)}</Text>}
                style={{ borderRadius: 8, padding: '6px 10px' }}
            />
            <GroupEditor
                group={value}
                depth={1}
                fields={fields}
                activeFields={activeFields}
                readOnly={readOnly}
                onChange={(g) => onChange(g)}
            />
            {!readOnly && (
                <Button size="small" danger icon={<DeleteOutlined />} onClick={() => onChange(null)}>
                    حذفِ کاملِ Rule
                </Button>
            )}
        </Space>
    );
}

function firstCondition(activeFields: ConditionField[]): RuleCondition {
    const field = activeFields[0];
    const ops = operatorOptions(field.DataType);

    return emptyCondition(field.Code, ops[0]?.value ?? 'EQ');
}

/* ============================ گروه (AND/OR) — بازگشتی ============================ */

interface GroupEditorProps {
    group: RuleGroup;
    depth: number;
    fields: ConditionField[];
    activeFields: ConditionField[];
    readOnly: boolean;
    onChange: (g: RuleGroup) => void;
}

function GroupEditor({ group, depth, fields, activeFields, readOnly, onChange }: GroupEditorProps) {
    const updateChild = (idx: number, next: RuleNode) => {
        const children = group.children.slice();
        children[idx] = next;
        onChange({ ...group, children });
    };
    const removeChild = (idx: number) => {
        onChange({ ...group, children: group.children.filter((_, i) => i !== idx) });
    };
    const addCondition = () => {
        onChange({ ...group, children: [...group.children, firstCondition(activeFields)] });
    };
    const addGroup = () => {
        onChange({ ...group, children: [...group.children, emptyGroup('AND')] });
    };

    return (
        <div
            style={{
                border: `1px dashed ${THEME.borderPrimary}`,
                borderRadius: 8,
                padding: 10,
                background: depth % 2 === 0 ? THEME.bgLighter : '#fff',
            }}
        >
            <Space style={{ marginBottom: 8 }} size={8}>
                <Text style={{ fontSize: 12 }}>ترکیب:</Text>
                <Radio.Group
                    size="small"
                    disabled={readOnly}
                    value={group.logic}
                    onChange={(e) => onChange({ ...group, logic: e.target.value })}
                    options={[
                        { label: 'همه (AND)', value: 'AND' },
                        { label: 'یکی (OR)', value: 'OR' },
                    ]}
                    optionType="button"
                    buttonStyle="solid"
                />
            </Space>

            {group.children.length === 0 && (
                <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 8 }}>
                    هنوز شرط/گروهی افزوده نشده.
                </Text>
            )}

            <Space direction="vertical" style={{ width: '100%' }} size={8}>
                {group.children.map((child, idx) =>
                    child.type === 'GROUP' ? (
                        <div key={idx} style={{ display: 'flex', gap: 6, alignItems: 'flex-start' }}>
                            <div style={{ flex: 1 }}>
                                <GroupEditor
                                    group={child}
                                    depth={depth + 1}
                                    fields={fields}
                                    activeFields={activeFields}
                                    readOnly={readOnly}
                                    onChange={(g) => updateChild(idx, g)}
                                />
                            </div>
                            {!readOnly && (
                                <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => removeChild(idx)} />
                            )}
                        </div>
                    ) : (
                        <ConditionEditor
                            key={idx}
                            condition={child}
                            fields={fields}
                            readOnly={readOnly}
                            onChange={(c) => updateChild(idx, c)}
                            onRemove={() => removeChild(idx)}
                        />
                    )
                )}
            </Space>

            {!readOnly && (
                <Space style={{ marginTop: 8 }} size={6}>
                    <Button size="small" icon={<PlusOutlined />} disabled={activeFields.length === 0} onClick={addCondition}>
                        افزودنِ شرط
                    </Button>
                    <Button size="small" icon={<PlusOutlined />} disabled={depth >= MAX_UI_DEPTH} onClick={addGroup}>
                        افزودنِ گروه
                    </Button>
                </Space>
            )}
        </div>
    );
}

/* ============================ یک شرط (CONDITION) ============================ */

interface ConditionEditorProps {
    condition: RuleCondition;
    fields: ConditionField[];
    readOnly: boolean;
    onChange: (c: RuleCondition) => void;
    onRemove: () => void;
}

function ConditionEditor({ condition, fields, readOnly, onChange, onRemove }: ConditionEditorProps) {
    const field = fields.find((f) => f.Code === condition.field);
    const fieldIsUnknownOrInactive = !field || !toBoolField(field.IsActive);
    const ops = field ? operatorOptions(field.DataType) : [];
    const unary = isUnaryOperator(condition.operator);
    const listOp = isListOperator(condition.operator);

    const fieldOptions = fields
        .filter((f) => toBoolField(f.IsActive) || f.Code === condition.field)
        .map((f) => ({
            value: f.Code,
            label: toBoolField(f.IsActive) ? f.DisplayName : `${f.DisplayName} (غیرفعال)`,
        }));

    const handleFieldChange = (code: string) => {
        const nf = fields.find((f) => f.Code === code);
        const nOps = nf ? operatorOptions(nf.DataType) : [];
        onChange({ type: 'CONDITION', field: code, operator: nOps[0]?.value ?? 'EQ' });
    };

    const handleOperatorChange = (op: RuleOperator) => {
        if (isUnaryOperator(op)) {
            onChange({ type: 'CONDITION', field: condition.field, operator: op });
        } else {
            onChange({ ...condition, operator: op, value: condition.value ?? { kind: 'CONSTANT', data: '' } });
        }
    };

    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 8 }}>
            <Space direction="vertical" style={{ width: '100%' }} size={6}>
                <Space wrap size={6}>
                    <Select
                        size="small"
                        style={{ width: 180 }}
                        placeholder="فیلد..."
                        showSearch
                        optionFilterProp="label"
                        disabled={readOnly}
                        value={condition.field || undefined}
                        options={fieldOptions}
                        onChange={handleFieldChange}
                    />
                    <Select
                        size="small"
                        style={{ width: 110 }}
                        disabled={readOnly || !field}
                        value={condition.operator}
                        options={ops}
                        onChange={handleOperatorChange}
                    />
                    {!readOnly && <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={onRemove} />}
                </Space>

                {fieldIsUnknownOrInactive && (
                    <Tag color="warning" style={{ width: 'fit-content' }}>
                        این فیلد غیرفعال یا نامعتبر است — این Rule تا Publish مسدود می‌ماند.
                    </Tag>
                )}

                {!unary && field && (
                    <ValueEditor
                        field={field}
                        listMode={listOp}
                        readOnly={readOnly}
                        value={condition.value?.data}
                        onChange={(data) => onChange({ ...condition, value: { kind: 'CONSTANT', data } })}
                    />
                )}
            </Space>
        </div>
    );
}

/* ============================ ورودیِ مقدار — بر اساسِ DataType ============================ */

interface ValueEditorProps {
    field: ConditionField;
    listMode: boolean;
    readOnly: boolean;
    value: string | number | boolean | (string | number)[] | undefined;
    onChange: (data: string | number | (string | number)[]) => void;
}

function ValueEditor({ field, listMode, readOnly, value, onChange }: ValueEditorProps) {
    if (listMode) {
        const arr = Array.isArray(value) ? value.map(String) : [];
        const options = field.DataType === 'SELECT' ? parseAllowedValues(field.AllowedValuesJson).map((v) => ({ value: v, label: v })) : undefined;

        return (
            <Select
                size="small"
                mode={options ? 'multiple' : 'tags'}
                style={{ width: '100%' }}
                disabled={readOnly}
                placeholder={options ? 'انتخابِ چند گزینه...' : 'چند شناسه را وارد کنید...'}
                value={arr}
                options={options}
                onChange={(vals: string[]) => {
                    if (options) {
                        onChange(vals);

                        return;
                    }
                    const nums = vals.map((v) => v.trim()).filter((v) => /^\d+$/.test(v));
                    onChange(nums);
                }}
            />
        );
    }

    switch (field.DataType) {
        case 'INTEGER':
            return (
                <InputNumber
                    size="small"
                    style={{ width: '100%' }}
                    precision={0}
                    disabled={readOnly}
                    value={typeof value === 'number' ? value : undefined}
                    onChange={(v) => onChange(v ?? 0)}
                    formatter={(v) => columnHelpers.formatNumberInput(v)}
                    parser={(v) => Number(columnHelpers.parseNumberInput(v))}
                />
            );
        case 'DECIMAL': {
            const str = typeof value === 'string' ? value : value != null ? String(value) : '';
            const invalid = str !== '' && !DECIMAL_PATTERN.test(str);

            // stringMode: مقدارِ خام همیشه رشته می‌ماند (هیچ‌گاه از float عبور نمی‌کند، نه در
            // value/onChange و نه در parser) — دقتِ اعشار/اعدادِ بزرگ دست‌نخورده می‌ماند؛ فقط
            // formatter/parserِ مشترک برایِ نمایشِ/حذفِ جداکنندهٔ هزارگان به کار می‌رود.
            return (
                <div>
                    <InputNumber
                        stringMode
                        size="small"
                        style={{ width: '100%', fontFamily: 'monospace', borderColor: invalid ? THEME.error : undefined }}
                        placeholder="مثلاً 1,500.25"
                        disabled={readOnly}
                        value={str === '' ? undefined : str}
                        formatter={(v) => columnHelpers.formatNumberInput(v)}
                        parser={(v) => columnHelpers.parseNumberInput(v)}
                        onChange={(v) => onChange(v == null ? '' : String(v))}
                    />
                    {invalid && (
                        <Text type="danger" style={{ fontSize: 11 }}>
                            فقط رقمِ لاتین و نقطهٔ اعشار مجاز است (بدونِ رقمِ فارسی).
                        </Text>
                    )}
                </div>
            );
        }
        case 'DATE':
            return (
                <PersianDateInput
                    value={typeof value === 'string' && value ? value : null}
                    onChange={(v) => onChange(v ?? '')}
                    disabled={readOnly}
                    size="small"
                />
            );
        case 'TIME':
            return (
                <TimePicker
                    size="small"
                    format="HH:mm"
                    style={{ width: '100%' }}
                    disabled={readOnly}
                    value={typeof value === 'string' && value ? dayjs(value, 'HH:mm') : null}
                    onChange={(v) => onChange(v ? v.format('HH:mm') : '')}
                />
            );
        case 'SELECT': {
            const options = parseAllowedValues(field.AllowedValuesJson).map((v) => ({ value: v, label: v }));

            return (
                <Select
                    size="small"
                    style={{ width: '100%' }}
                    disabled={readOnly}
                    value={typeof value === 'string' ? value : undefined}
                    options={options}
                    onChange={(v) => onChange(v)}
                />
            );
        }
        case 'USER':
        case 'UNIT':
            return (
                <InputNumber
                    size="small"
                    style={{ width: '100%' }}
                    min={1}
                    precision={0}
                    placeholder="شناسهٔ عددی"
                    disabled={readOnly}
                    value={typeof value === 'number' ? value : undefined}
                    onChange={(v) => onChange(v ?? 0)}
                />
            );
        case 'STRING':
        default:
            return (
                <Input
                    size="small"
                    disabled={readOnly}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}
