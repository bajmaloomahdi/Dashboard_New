import { Space, Table } from 'antd';
import type { TableProps } from 'antd';
import type { ReactNode } from 'react';
import { applyGridColumnSettings, useGridColumnSettings } from './GridColumnSettings';
import { withGridSorting } from '../Utils/gridSorting';

export default function PreferenceTable<Row extends object>({ gridKey, columns, toolbar, ...props }: TableProps<Row> & { gridKey: string; toolbar?: ReactNode }) {
    const settings = useGridColumnSettings(gridKey, columns?.map((column) => String(column.key)));
    const sortableColumns = columns && withGridSorting(columns, props.dataSource ?? []);
    return (
        <>
            {toolbar ? <Space size={8} wrap style={{ marginBottom: 8 }}>{toolbar}{settings.control}</Space> : settings.control}
            <Table<Row> {...props} columns={sortableColumns && applyGridColumnSettings(sortableColumns, settings)} />
        </>
    );
}
