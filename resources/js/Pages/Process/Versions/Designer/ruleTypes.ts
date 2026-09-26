/**
 * قراردادِ Condition Engine در سمتِ Frontend — دقیقاً هم‌ارزِ Backend:
 *   app/Services/Workflow/Support/ConditionOperators.php
 *   app/Services/Workflow/Support/ConditionDataTypeCaster.php
 *
 * هیچ eval/SQL Dynamic اینجا نیست؛ فقط ساختِ/Editِ همان RuleJsonِ ساختاریافته‌ای
 * که Backend در ConditionRuleValidator/ConditionEvaluator مصرف می‌کند.
 */
import type { RuleCondition, RuleGroup, RuleNode, RuleOperator } from '../versionEditor';

export type ConditionDataType = 'STRING' | 'INTEGER' | 'DECIMAL' | 'DATE' | 'TIME' | 'BOOLEAN' | 'SELECT' | 'USER' | 'UNIT';

export interface ConditionField {
    FieldID: number;
    Code: string;
    DisplayName: string;
    DataType: ConditionDataType;
    SourceType: 'START_CONTEXT';
    SourceKey: string;
    AllowedValuesJson: string | null;
    Description: string | null;
    SortOrder: number;
    IsActive: boolean | number | string;
}

export const DATA_TYPE_OPTIONS: { value: ConditionDataType; label: string }[] = [
    { value: 'STRING', label: 'متن (STRING)' },
    { value: 'INTEGER', label: 'عددِ صحیح (INTEGER)' },
    { value: 'DECIMAL', label: 'عددِ اعشاری (DECIMAL)' },
    { value: 'DATE', label: 'تاریخ (DATE)' },
    { value: 'TIME', label: 'ساعت (TIME)' },
    { value: 'BOOLEAN', label: 'درست/نادرست (BOOLEAN)' },
    { value: 'SELECT', label: 'انتخابی (SELECT)' },
    { value: 'USER', label: 'کاربر (USER)' },
    { value: 'UNIT', label: 'واحدِ سازمانی (UNIT)' },
];

/** هم‌ارزِ دقیقِ ConditionOperators::MATRIX در Backend — تغییرش بدونِ هماهنگی با آن‌جا ممنوع است. */
export const OPERATORS_BY_DATATYPE: Record<ConditionDataType, RuleOperator[]> = {
    INTEGER: ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
    DECIMAL: ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
    DATE: ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
    TIME: ['EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE'],
    BOOLEAN: ['IS_TRUE', 'IS_FALSE'],
    STRING: ['EQ', 'NE', 'CONTAINS', 'IS_EMPTY', 'IS_NOT_EMPTY'],
    SELECT: ['EQ', 'NE', 'IN', 'NOT_IN'],
    USER: ['EQ', 'NE', 'IN', 'NOT_IN'],
    UNIT: ['EQ', 'NE', 'IN', 'NOT_IN'],
};

export const UNARY_OPERATORS = new Set<RuleOperator>(['IS_EMPTY', 'IS_NOT_EMPTY', 'IS_TRUE', 'IS_FALSE']);
export const LIST_OPERATORS = new Set<RuleOperator>(['IN', 'NOT_IN']);

export const OPERATOR_LABELS: Record<RuleOperator, string> = {
    EQ: '=', NE: '≠', GT: '>', GTE: '≥', LT: '<', LTE: '≤',
    CONTAINS: 'شاملِ', IN: 'یکی از', NOT_IN: 'هیچ‌کدام از',
    IS_EMPTY: 'خالی است', IS_NOT_EMPTY: 'خالی نیست', IS_TRUE: 'درست است', IS_FALSE: 'نادرست است',
};

export function operatorOptions(dataType: ConditionDataType): { value: RuleOperator; label: string }[] {
    return OPERATORS_BY_DATATYPE[dataType].map((op) => ({ value: op, label: OPERATOR_LABELS[op] }));
}

export function isUnaryOperator(op: RuleOperator): boolean {
    return UNARY_OPERATORS.has(op);
}

export function isListOperator(op: RuleOperator): boolean {
    return LIST_OPERATORS.has(op);
}

export function parseAllowedValues(json: string | null): string[] {
    if (!json) return [];
    try {
        const arr = JSON.parse(json);

        return Array.isArray(arr) ? arr.map(String) : [];
    } catch {
        return [];
    }
}

/** الگویِ دقیقاً هم‌ارزِ ConditionDataTypeCaster::castDecimal — فقط برایِ Validationِ سمتِ کلاینت. */
export const DECIMAL_PATTERN = /^-?\d+(\.\d+)?$/;
export const INTEGER_PATTERN = /^-?\d+$/;
export const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
export const TIME_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

export function emptyCondition(fieldCode: string, operator: RuleOperator): RuleCondition {
    return { type: 'CONDITION', field: fieldCode, operator };
}

export function emptyGroup(logic: 'AND' | 'OR' = 'AND'): RuleGroup {
    return { version: 1, type: 'GROUP', logic, children: [] };
}

/** نمایشِ خوانا برایِ کاربر (نه برایِ Evaluate) — از DisplayName استفاده می‌کند، نه Code. */
export function summarizeRule(node: RuleNode, fieldsByCode: Map<string, ConditionField>): string {
    if (node.type === 'GROUP') {
        const parts = node.children.map((c) => summarizeRule(c, fieldsByCode));
        if (parts.length === 0) return '(خالی)';
        const joined = parts.join(node.logic === 'OR' ? ' یا ' : ' و ');

        return parts.length > 1 ? `(${joined})` : joined;
    }

    const field = fieldsByCode.get(node.field);
    const fieldLabel = field?.DisplayName ?? node.field;
    const opLabel = OPERATOR_LABELS[node.operator] ?? node.operator;

    if (isUnaryOperator(node.operator)) {
        return `${fieldLabel} ${opLabel}`;
    }

    const data = node.value?.data;
    const valueLabel = Array.isArray(data) ? data.join('، ') : String(data ?? '؟');

    return `${fieldLabel} ${opLabel} ${valueLabel}`;
}
