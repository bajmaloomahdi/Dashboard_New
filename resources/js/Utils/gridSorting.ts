import type { TableProps } from 'antd';

type Columns<Row> = NonNullable<TableProps<Row>['columns']>;
const collator = new Intl.Collator('fa', { numeric: true, sensitivity: 'base' });

export function compareGridValues(a: unknown, b: unknown): number {
    if (a === b) return 0;
    if (a == null || a === '') return 1;
    if (b == null || b === '') return -1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return collator.compare(String(a), String(b));
}

export function withGridSorting<Row extends object>(columns: Columns<Row>, rows: readonly Row[]): Columns<Row> {
    return columns.map((column) => {
        if ('children' in column) return { ...column, children: withGridSorting(column.children, rows) };
        if (column.sorter !== undefined) return column;
        const index = column.dataIndex ?? column.key;
        if (index == null || /^(?:__rowNumber|row|index|actions?|operations?)$/i.test(String(index))) return column;
        const path = Array.isArray(index) ? index : [index];
        const read = (row: Row): unknown => path.reduce<unknown>((value, key) =>
            value != null && typeof value === 'object' ? (value as Record<PropertyKey, unknown>)[key as PropertyKey] : undefined, row);
        if (!column.dataIndex && !rows.some((row) => ['string', 'number', 'boolean'].includes(typeof read(row)))) return column;
        return { ...column, sorter: (a: Row, b: Row) => compareGridValues(read(a), read(b)) };
    });
}
