import { useMemo, useState } from 'react';
import { Table, Input, Typography, Tag, Empty } from 'antd';
import { SearchOutlined, LoadingOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { THEME, STYLES, TABLE_CLASS_NAME, TABLE_CSS, columnHelpers } from '../theme';

const { Text } = Typography;

interface DataGridProps {
    columns: string[];
    dataSource: any[];
    loading?: boolean;
    showRowNumber?: boolean;
    showColumnSearch?: boolean;
    autoFormat?: boolean;
    pageSize?: number;
    rowKey?: string;
    scrollY?: number | string;
    customColumns?: ColumnsType<any>;
}

export default function DataGrid({
    columns,
    dataSource,
    loading = false,
    showRowNumber = true,
    showColumnSearch = true,
    autoFormat = true,
    pageSize = 20,
    rowKey = '__key',
    scrollY = 600,
    customColumns,
}: DataGridProps) {
    const [columnFilters, setColumnFilters] = useState<Record<string, string>>({});

    const filteredData = useMemo(() => {
        if (!dataSource) return [];
        return dataSource.filter((row) => {
            return Object.entries(columnFilters).every(([col, filter]) => {
                if (!filter) return true;
                return String(row[col] ?? '').toLowerCase().includes(filter.toLowerCase());
            });
        });
    }, [dataSource, columnFilters]);

    const tableColumns = useMemo(() => {
        // اگر ستون‌های سفارشی داده شده
        if (customColumns) {
            const cols: any[] = [];

            if (showRowNumber) {
                cols.push({
                    title: 'ردیف',
                    key: '__rowNumber',
                    width: 70,
                    align: 'center' as const,
                    fixed: 'right' as const,
                    render: (_: any, __: any, index: number) => (
                        <div style={STYLES.rowNumber}>
                            {index + 1}
                        </div>
                    ),
                });
            }

            return [...cols, ...customColumns];
        }

        // ستون‌های داینامیک
        const cols: any[] = [];

        if (showRowNumber) {
            cols.push({
                title: 'ردیف',
                key: '__rowNumber',
                width: 70,
                align: 'center' as const,
                fixed: 'right' as const,
                render: (_: any, __: any, index: number) => (
                    <div style={STYLES.rowNumber}>
                        {index + 1}
                    </div>
                ),
            });
        }

        columns.forEach((col) => {
            const sampleValue = dataSource?.[0]?.[col];
            const isDate = autoFormat && columnHelpers.isDateColumn(col, sampleValue);
            const isCode = autoFormat && columnHelpers.isCodeColumn(col);
            const isNumeric = autoFormat && !isCode && !isDate && columnHelpers.isNumericValue(sampleValue);

            cols.push({
                title: showColumnSearch ? (
                    <div>
                        <div style={{
                            marginBottom: 6,
                            fontSize: 13,
                            fontWeight: 600,
                            color: '#1F2937',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: 6
                        }}>
                            {isDate && '📅'}
                            {isCode && '🔢'}
                            {isNumeric && '💰'}
                            {col}
                        </div>
                        <Input
                            size="small"
                            placeholder="جستجو..."
                            prefix={<SearchOutlined style={{ fontSize: 11, color: THEME.primary }} />}
                            value={columnFilters[col] || ''}
                            onChange={(e) => setColumnFilters((prev) => ({ ...prev, [col]: e.target.value }))}
                            onClick={(e) => e.stopPropagation()}
                            allowClear
                            style={{
                                fontWeight: 'normal',
                                borderRadius: 6,
                                fontSize: 12,
                            }}
                        />
                    </div>
                ) : col,
                dataIndex: col,
                key: col,
                align: 'center' as const,
                render: (value: any) => {
                    if (value === null || value === undefined)
                        return <Text type="secondary" style={{ opacity: 0.5 }}>—</Text>;
                    if (typeof value === 'boolean')
                        return value
                            ? <Tag color="success" style={{ margin: 0 }}>بله</Tag>
                            : <Tag color="default" style={{ margin: 0 }}>خیر</Tag>;

                    if (isDate) {
                        return (
                            <span style={STYLES.dateBadge}>
                                {columnHelpers.formatDate(value)}
                            </span>
                        );
                    }

                    if (isCode) {
                        return (
                            <span style={STYLES.codeBadge}>
                                {String(value)}
                            </span>
                        );
                    }

                    if (isNumeric) {
                        return (
                            <span style={STYLES.numberBadge}>
                                {columnHelpers.formatNumber(value)}
                            </span>
                        );
                    }

                    return <span style={{ color: THEME.textPrimary }}>{String(value)}</span>;
                },
            });
        });

        return cols;
    }, [columns, dataSource, columnFilters, showRowNumber, showColumnSearch, autoFormat, customColumns]);

    const totalCount = dataSource?.length || 0;
    const filteredCount = filteredData?.length || 0;

    return (
        <>
            <Table
                className={TABLE_CLASS_NAME}
                columns={tableColumns}
                dataSource={filteredData.map((row, index) => ({ ...row, __key: index }))}
                rowKey={rowKey === '__key' ? '__key' : rowKey}
                loading={{
                    spinning: loading,
                    indicator: <LoadingOutlined style={{ fontSize: 32, color: THEME.primary }} spin />,
                }}
                pagination={{
                    pageSize: pageSize,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    showTotal: (total, range) => (
                        <span style={{ fontSize: 13 }}>
                            نمایش <strong>{range[0]}</strong> تا <strong>{range[1]}</strong> از{' '}
                            <strong>{columnHelpers.formatNumber(total)}</strong> ردیف
                            {filteredCount !== totalCount && totalCount > 0 && (
                                <span style={{ color: THEME.warning, marginRight: 8 }}>
                                    (از {columnHelpers.formatNumber(totalCount)} کل)
                                </span>
                            )}
                        </span>
                    ),
                }}
                scroll={{ x: 'max-content', y: scrollY }}
                bordered
                size="middle"
                rowClassName={(_, index) => index % 2 === 0 ? 'row-even' : 'row-odd'}
                locale={{
                    emptyText: <Empty description="داده‌ای برای نمایش وجود ندارد" />,
                }}
            />

            <style>{TABLE_CSS}</style>
        </>
    );
}