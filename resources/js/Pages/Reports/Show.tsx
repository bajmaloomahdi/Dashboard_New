import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Typography,
    Row,
    Col,
    Select,
    Checkbox,
    Spin,
    Empty,
    Tag,
} from 'antd';
import {
    PlayCircleOutlined,
    FileExcelOutlined,
    BarChartOutlined,
    LoadingOutlined,
    InfoCircleOutlined,
    ReloadOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import DatePicker, { DateObject } from 'react-multi-date-picker';
import persian from 'react-date-object/calendars/persian';
import persian_fa from 'react-date-object/locales/persian_fa';
import ExcelJS from 'exceljs';
import { saveAs } from 'file-saver';
import axios from 'axios';
import { THEME, STYLES, columnHelpers } from '../../theme';

const { Title, Text } = Typography;

interface Report {
    ReportID: number;
    ReportCode: string;
    ReportTitle: string;
    ProcedureName: string;
    CommandTimeout: number;
    AllowExcel: boolean | number;
    Description: string | null;
}

interface Parameter {
    ReportParameterID: number;
    ParameterName: string;
    ParameterCaption: string;
    DataType: string;
    ControlType: string;
    DefaultValue: string | null;
    IsRequired: boolean | number;
    IsVisible: boolean | number;
    SortOrder: number;
    LookupProcedure: string | null;
    Description: string | null;
}

/**
 * تبدیل اعداد فارسی/عربی به انگلیسی
 */
const toEnglishDigits = (str: string): string => {
    const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    let result = String(str);
    for (let i = 0; i < 10; i++) {
        result = result.replace(new RegExp(persian[i], 'g'), String(i));
        result = result.replace(new RegExp(arabic[i], 'g'), String(i));
    }
    return result;
};

const dateToShamsi = (date: Date | null): string => {
    if (!date) return '';
    const d = new DateObject({ date, calendar: persian, locale: persian_fa });
    const formatted = d.format('YYYYMMDD');
    // تضمین اعداد انگلیسی
    return toEnglishDigits(formatted);
};

const getStartOfCurrentPersianYear = (): Date => {
    const now = new DateObject({ calendar: persian, locale: persian_fa });
    const year = now.year;
    const startOfYear = new DateObject({
        year: year,
        month: 1,
        day: 1,
        calendar: persian,
        locale: persian_fa,
    });
    return startOfYear.toDate();
};

const getToday = (): Date => {
    return new Date();
};

/**
 * کامپوننت تاریخ سفارشی - تایپ عدد و / خودکار
 */
/**
 * کامپوننت تاریخ سفارشی - تایپ عدد و / خودکار + دکمه تقویم
 */
const DateInputCustom = ({ value, onChange }: {
    value: Date | null;
    onChange: (date: Date | null) => void;
}) => {
    const [inputValue, setInputValue] = useState('');

    // مقداردهی اولیه از تاریخ
    useEffect(() => {
        if (value) {
            const d = new DateObject({ date: value, calendar: persian, locale: persian_fa });
            setInputValue(toEnglishDigits(d.format('YYYY/MM/DD')));
        } else {
            setInputValue('');
        }
    }, [value]);

    /**
     * فرمت خودکار
     */
    const formatWithSlash = (raw: string): string => {
        const digits = toEnglishDigits(raw).replace(/\D/g, '');
        if (digits.length === 0) return '';
        if (digits.length <= 4) return digits;
        if (digits.length <= 6) return `${digits.substring(0, 4)}/${digits.substring(4)}`;
        return `${digits.substring(0, 4)}/${digits.substring(4, 6)}/${digits.substring(6, 8)}`;
    };

    const parseDate = (str: string): Date | null => {
        const digits = toEnglishDigits(str).replace(/\D/g, '');
        if (digits.length !== 8) return null;

        const year = parseInt(digits.substring(0, 4));
        const month = parseInt(digits.substring(4, 6));
        const day = parseInt(digits.substring(6, 8));

        if (year < 1300 || year > 1500) return null;
        if (month < 1 || month > 12) return null;
        if (day < 1 || day > 31) return null;

        try {
            const d = new DateObject({
                year, month, day,
                calendar: persian,
                locale: persian_fa,
            });
            return d.toDate();
        } catch {
            return null;
        }
    };

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const formatted = formatWithSlash(raw);
        setInputValue(formatted);

        const digits = toEnglishDigits(raw).replace(/\D/g, '');
        if (digits.length === 8) {
            const parsed = parseDate(formatted);
            if (parsed) onChange(parsed);
        } else if (digits.length === 0) {
            onChange(null);
        }
    };

    return (
        <div style={{ position: 'relative', width: '100%' }}>
            <Input
                value={inputValue}
                onChange={handleInputChange}
                placeholder="مثال: 14050101"
                size="large"
                maxLength={10}
                style={{
                    direction: 'ltr',
                    textAlign: 'center',
                    fontFamily: 'monospace',
                    fontSize: 14,
                    letterSpacing: 1,
                    paddingLeft: 40,
                }}
            />

            {/* دکمه تقویم روی input */}
            <div style={{
                position: 'absolute',
                left: 8,
                top: '50%',
                transform: 'translateY(-50%)',
                zIndex: 10,
            }}>
                <DatePicker
                    value={value}
                    onChange={(date: any) => {
                        onChange(date ? date.toDate() : null);
                    }}
                    calendar={persian}
                    locale={persian_fa}
                    calendarPosition="bottom-left"
                    format="YYYY/MM/DD"
                    render={(_value, openCalendar) => (
                        <button
                            type="button"
                            onClick={openCalendar}
                            style={{
                                background: 'transparent',
                                border: 'none',
                                cursor: 'pointer',
                                fontSize: 20,
                                padding: 4,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                color: THEME.primary,
                            }}
                            title="انتخاب از تقویم"
                        >
                            📅
                        </button>
                    )}
                />
            </div>
        </div>
    );
};
            

export default function ReportShow() {
    const { report, parameters } = usePage().props as any;

    const [paramValues, setParamValues] = useState<Record<string, any>>({});
    const [lookupData, setLookupData] = useState<Record<string, any[]>>({});
    const [executing, setExecuting] = useState(false);
    const [results, setResults] = useState<any[]>([]);
    const [columns, setColumns] = useState<string[]>([]);
    const [hasExecuted, setHasExecuted] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const initialValues: Record<string, any> = {};

        parameters?.forEach((p: Parameter) => {
            if (p.ParameterName === 'StartDate' && !p.DefaultValue) {
                initialValues[p.ParameterName] = getStartOfCurrentPersianYear();
            } else if (p.ParameterName === 'EndDate' && !p.DefaultValue) {
                initialValues[p.ParameterName] = getToday();
            } else if (p.DefaultValue) {
                initialValues[p.ParameterName] = p.DefaultValue;
            } else if (p.ControlType === 'MULTISELECT') {
                initialValues[p.ParameterName] = [];
            } else if (p.ControlType === 'CHECKBOX') {
                initialValues[p.ParameterName] = false;
            }
        });

        setParamValues(initialValues);

        parameters?.forEach((p: Parameter) => {
            if ((p.ControlType === 'SELECT' || p.ControlType === 'MULTISELECT') && p.LookupProcedure) {
                loadLookupData(p.ParameterName, p.LookupProcedure);
            }
        });

        if (!parameters || parameters.length === 0) {
            executeReport({});
        }
    }, [parameters]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const loadLookupData = async (paramName: string, procedureName: string) => {
        try {
            const response = await axios.get('/reports/lookup', { params: { procedure_name: procedureName } });
            if (response.data.success) {
                setLookupData((prev) => ({ ...prev, [paramName]: response.data.data }));
            }
        } catch (error) {
            console.error('Error loading lookup data:', error);
        }
    };

    const handleParamChange = (name: string, value: any) => {
        setParamValues((prev) => ({ ...prev, [name]: value }));
    };

    const executeReport = async (params?: Record<string, any>) => {
        const finalParams = params || paramValues;

        const missingRequired = parameters?.filter((p: Parameter) => {
            if (!columnHelpers.toBool(p.IsRequired)) return false;
            const value = finalParams[p.ParameterName];
            if (value === undefined || value === null || value === '') return true;
            if (Array.isArray(value) && value.length === 0) return true;
            return false;
        });

        if (missingRequired && missingRequired.length > 0) {
            showNotification('warning', `پارامتر "${missingRequired[0].ParameterCaption}" الزامی است`);
            return;
        }

        setExecuting(true);
        try {
            const preparedParams: Record<string, any> = {};
            Object.keys(finalParams).forEach((key) => {
                let value = finalParams[key];
                if (Array.isArray(value)) value = value.length > 0 ? value.join(',') : '-1';
                if (value instanceof Date) value = dateToShamsi(value);
                // تضمین اعداد انگلیسی برای هر مقدار رشته‌ای
                if (typeof value === 'string') value = toEnglishDigits(value);
                preparedParams[key] = value;
            });

            const response = await axios.post(`/reports/${report.ReportCode}/execute`, {
                parameters: preparedParams,
            });

            const result = response.data;

            if (result.success) {
                setResults(result.data || []);
                setColumns(result.columns || []);
                setHasExecuted(true);

                if (result.data.length === 0) showNotification('info', 'گزارش اجرا شد اما داده‌ای یافت نشد');
                else showNotification('success', `${result.total} ردیف بارگذاری شد`);
            } else {
                showNotification('error', result.message || 'خطا در اجرای گزارش');
            }
        } catch (error: any) {
            const message = error.response?.data?.message || 'خطا در برقراری ارتباط با سرور';
            showNotification('error', message);
        } finally {
            setExecuting(false);
        }
    };

    const exportToExcel = async () => {
        if (results.length === 0) {
            showNotification('warning', 'داده‌ای برای خروجی وجود ندارد');
            return;
        }

        try {
            const COLORS = THEME.excel;
            const workbook = new ExcelJS.Workbook();
            workbook.creator = 'Dashboard';
            workbook.created = new Date();

            const worksheet = workbook.addWorksheet(report.ReportTitle.substring(0, 30), {
                views: [{ rightToLeft: true, state: 'frozen', ySplit: 1 }],
            });

            const headers = ['ردیف', ...columns];
            worksheet.addRow(headers);

            results.forEach((row, index) => {
                const rowData: any[] = [index + 1];
                columns.forEach((col) => {
                    let value = row[col];
                    const isDate = columnHelpers.isDateColumn(col, value);
                    const isCode = columnHelpers.isCodeColumn(col);

                    if (isDate && value) value = columnHelpers.formatDate(value);
                    else if (isCode && value !== null && value !== undefined) value = String(value);
                    else if (columnHelpers.isNumericValue(value)) {
                        const num = parseFloat(String(value).replace(/,/g, ''));
                        if (!isNaN(num)) value = Math.round(num);
                    }
                    rowData.push(value ?? '');
                });
                worksheet.addRow(rowData);
            });

            worksheet.getColumn(1).width = 10;
            columns.forEach((col, index) => {
                const maxLength = Math.max(col.length + 5, ...results.map((row) => String(row[col] ?? '').length));
                worksheet.getColumn(index + 2).width = Math.min(maxLength + 4, 45);
            });

            const headerRow = worksheet.getRow(1);
            headerRow.height = 38;
            headerRow.eachCell((cell) => {
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.headerBg } };
                cell.font = { name: 'B Nazanin', size: 13, bold: true, color: { argb: COLORS.headerText } };
                cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true, readingOrder: 'rtl' };
                cell.border = {
                    top: { style: 'medium', color: { argb: COLORS.headerBorderColor } },
                    bottom: { style: 'medium', color: { argb: COLORS.headerBorderColor } },
                    left: { style: 'thin', color: { argb: COLORS.headerBorderColor } },
                    right: { style: 'thin', color: { argb: COLORS.headerBorderColor } },
                };
            });

            for (let rowIndex = 2; rowIndex <= results.length + 1; rowIndex++) {
                const row = worksheet.getRow(rowIndex);
                row.height = 24;
                const isEvenRow = rowIndex % 2 === 0;
                const bgColor = isEvenRow ? COLORS.evenRowBg : COLORS.oddRowBg;

                row.eachCell((cell, colNumber) => {
                    const isRowNumber = colNumber === 1;
                    const columnName = isRowNumber ? 'ردیف' : columns[colNumber - 2];
                    const isDate = columnName && columnHelpers.isDateColumn(columnName, results[rowIndex - 2]?.[columnName]);
                    const isCode = columnName && columnHelpers.isCodeColumn(columnName);

                    cell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: isRowNumber ? (isEvenRow ? COLORS.rowNumberBgEven : COLORS.rowNumberBgOdd) : bgColor,
                        },
                    };
                    cell.font = {
                        name: 'B Nazanin',
                        size: 11,
                        bold: isRowNumber,
                        color: { argb: isRowNumber ? COLORS.rowNumberText : COLORS.dataText },
                    };
                    cell.alignment = { horizontal: 'center', vertical: 'middle', readingOrder: 'rtl' };
                    cell.border = {
                        top: { style: 'thin', color: { argb: COLORS.borderColor } },
                        bottom: { style: 'thin', color: { argb: COLORS.borderColor } },
                        left: { style: 'thin', color: { argb: COLORS.borderColor } },
                        right: { style: 'thin', color: { argb: COLORS.borderColor } },
                    };
                    if (columnName && !isCode && !isDate && !isRowNumber) {
                        if (typeof cell.value === 'number') cell.numFmt = '#,##0';
                    }
                });
            }

            worksheet.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: headers.length } };

            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });

            const fileName = `${report.ReportCode}_${new Date().toISOString().split('T')[0]}.xlsx`;
            saveAs(blob, fileName);

            showNotification('success', 'فایل Excel با موفقیت دانلود شد');
        } catch (error) {
            console.error(error);
            showNotification('error', 'خطا در ایجاد فایل Excel');
        }
    };

    const renderParameterControl = (param: Parameter) => {
        const value = paramValues[param.ParameterName];

        switch (param.ControlType) {
            case 'TEXTBOX':
                return <Input value={value || ''} onChange={(e) => handleParamChange(param.ParameterName, e.target.value)} placeholder={`${param.ParameterCaption} را وارد کنید`} size="large" />;

            case 'NUMBER':
                return <Input type="number" value={value || ''} onChange={(e) => handleParamChange(param.ParameterName, e.target.value)} placeholder={`${param.ParameterCaption} را وارد کنید`} size="large" />;

            case 'DATE':
                return (
                    <DateInputCustom
                        value={value}
                        onChange={(date) => handleParamChange(param.ParameterName, date)}
                    />
                );

            case 'SELECT':
                return (
                    <Select
                        value={value}
                        onChange={(val) => handleParamChange(param.ParameterName, val)}
                        placeholder={`${param.ParameterCaption} را انتخاب کنید`}
                        size="large"
                        showSearch
                        allowClear
                        style={{ width: '100%' }}
                        optionFilterProp="label"
                        options={(lookupData[param.ParameterName] || []).map((item) => ({
                            value: item.Value,
                            label: item.Text,
                        }))}
                    />
                );

            case 'MULTISELECT': {
                const isAllSelected = (value || []).includes(-1);

                return (
                    <Select
                        mode="multiple"
                        value={value || []}
                        onChange={(val) => {
                            const currentValues = value || [];
                            const justAddedAll = val.includes(-1) && !currentValues.includes(-1);
                            const addedOtherWhileAllActive = currentValues.includes(-1) &&
                                val.some((v: any) => v !== -1) &&
                                val.includes(-1);

                            if (justAddedAll) {
                                handleParamChange(param.ParameterName, [-1]);
                            } else if (addedOtherWhileAllActive) {
                                handleParamChange(param.ParameterName, val.filter((v: any) => v !== -1));
                            } else {
                                handleParamChange(param.ParameterName, val);
                            }
                        }}
                        placeholder={`${param.ParameterCaption} (چند انتخابی)`}
                        size="large"
                        showSearch
                        allowClear
                        style={{ width: '100%' }}
                        optionFilterProp="label"
                        maxTagCount="responsive"
                        menuItemSelectedIcon={null}
                        optionRender={(option) => {
                            const isDisabled = isAllSelected && option.value !== -1;
                            const isChecked = (value || []).includes(option.value);

                            return (
                                <div style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    padding: '2px 0',
                                    opacity: isDisabled ? 0.4 : 1,
                                    cursor: isDisabled ? 'not-allowed' : 'pointer',
                                }}>
                                    <input
                                        type="checkbox"
                                        checked={isChecked}
                                        readOnly
                                        disabled={isDisabled}
                                        style={{
                                            width: 16,
                                            height: 16,
                                            cursor: isDisabled ? 'not-allowed' : 'pointer',
                                            accentColor: THEME.primary,
                                        }}
                                    />
                                    <span>{option.label}</span>
                                    {option.value === -1 && (
                                        <span style={{
                                            fontSize: 10,
                                            color: THEME.warning,
                                            marginRight: 'auto'
                                        }}>
                                            (انتخاب کل)
                                        </span>
                                    )}
                                </div>
                            );
                        }}
                        options={[
                            { value: -1, label: '⭐ همه', disabled: false },
                            ...(lookupData[param.ParameterName] || []).map((item) => ({
                                value: item.Value,
                                label: item.Text,
                                disabled: isAllSelected,
                            })),
                        ]}
                    />
                );
            }

            case 'CHECKBOX':
                return <Checkbox checked={!!value} onChange={(e) => handleParamChange(param.ParameterName, e.target.checked)}>{param.ParameterCaption}</Checkbox>;

            default:
                return <Input size="large" />;
        }
    };

    const hasParameters = parameters && parameters.length > 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<BarChartOutlined />}
                title={report.ReportTitle}
                subtitle={report.Description || undefined}
                tags={[{ label: `کد: ${report.ReportCode}` }]}
                stats={
                    results.length > 0
                        ? [{ icon: <BarChartOutlined />, label: 'نتایج', value: `${columnHelpers.formatNumber(results.length)} ردیف` }]
                        : undefined
                }
            />

            {hasParameters && (
                <Card
                    title={
                        <Space size={10}>
                            <div style={STYLES.iconBox}>
                                <InfoCircleOutlined style={{ color: '#fff', fontSize: 16 }} />
                            </div>
                            <div>
                                <span style={{ fontSize: 15, fontWeight: 600, color: THEME.textPrimary }}>
                                    پارامترهای گزارش
                                </span>
                                <div style={{ fontSize: 11, color: THEME.textSecondary, marginTop: 2 }}>
                                    لطفاً مقادیر مورد نظر خود را انتخاب کنید
                                </div>
                            </div>
                        </Space>
                    }
                    extra={
                        <Button
                            type="primary"
                            icon={<PlayCircleOutlined />}
                            size="large"
                            loading={executing}
                            onClick={() => executeReport()}
                            style={{
                                ...STYLES.successButton,
                                height: 44,
                                paddingLeft: 24,
                                paddingRight: 24,
                            }}
                        >
                            نمایش گزارش
                        </Button>
                    }
                    style={{ marginBottom: 16, ...STYLES.card }}
                    styles={{
                        header: STYLES.cardHeader,
                        body: { padding: '20px', background: THEME.bgLighter },
                    }}
                >
                    <Row gutter={[16, 16]}>
                        {parameters
                            .filter((p: Parameter) => columnHelpers.toBool(p.IsVisible))
                            .sort((a: Parameter, b: Parameter) => a.SortOrder - b.SortOrder)
                            .map((param: Parameter) => {
                                const isRequired = columnHelpers.toBool(param.IsRequired);
                                const value = paramValues[param.ParameterName];
                                const hasValue = value !== undefined && value !== null && value !== '' &&
                                    !(Array.isArray(value) && value.length === 0);

                                const getIcon = () => {
                                    switch (param.ControlType) {
                                        case 'DATE': return '📅';
                                        case 'SELECT': return '📋';
                                        case 'MULTISELECT': return '☑';
                                        case 'NUMBER': return '🔢';
                                        case 'CHECKBOX': return '✅';
                                        default: return '📝';
                                    }
                                };

                                return (
                                    <Col xs={24} sm={12} md={8} key={param.ReportParameterID}>
                                        <div style={{
                                            background: '#fff',
                                            border: `2px solid ${hasValue ? THEME.success : (isRequired ? THEME.error : THEME.border)}`,
                                            borderRadius: 10,
                                            padding: '14px',
                                            transition: 'all 0.3s',
                                            boxShadow: hasValue
                                                ? '0 2px 8px rgba(16, 185, 129, 0.1)'
                                                : '0 1px 3px rgba(0,0,0,0.04)',
                                            height: '100%',
                                        }}>
                                            <div style={{
                                                marginBottom: 10,
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'space-between',
                                            }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    <span style={{ fontSize: 16 }}>{getIcon()}</span>
                                                    <Text strong style={{ fontSize: 13, color: THEME.textPrimary }}>
                                                        {param.ParameterCaption}
                                                    </Text>
                                                    {isRequired && (
                                                        <Tag color="red" style={{ margin: 0, fontSize: 10, padding: '0 6px', lineHeight: '16px', borderRadius: 4 }}>
                                                            الزامی
                                                        </Tag>
                                                    )}
                                                </div>
                                                {hasValue && (
                                                    <Tag color="success" style={{ margin: 0, fontSize: 10, padding: '0 6px', lineHeight: '16px', borderRadius: 4 }}>
                                                        ✓
                                                    </Tag>
                                                )}
                                            </div>

                                            {renderParameterControl(param)}

                                            {param.Description && (
                                                <div style={{
                                                    marginTop: 8,
                                                    fontSize: 11,
                                                    color: THEME.textSecondary,
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 4,
                                                }}>
                                                    <InfoCircleOutlined style={{ fontSize: 10 }} />
                                                    {param.Description}
                                                </div>
                                            )}
                                        </div>
                                    </Col>
                                );
                            })}
                    </Row>
                </Card>
            )}

            <Card
                title={
                    <Space>
                        <BarChartOutlined style={{ color: THEME.primary }} />
                        <span>نتایج گزارش</span>
                        {results.length > 0 && (
                            <Tag color="purple" style={{ borderRadius: 6 }}>
                                {columnHelpers.formatNumber(results.length)} ردیف
                            </Tag>
                        )}
                    </Space>
                }
                extra={
                    <Space>
                        {!hasParameters && (
                            <Button icon={<ReloadOutlined />} onClick={() => executeReport()} loading={executing}>
                                به‌روزرسانی
                            </Button>
                        )}
                        {columnHelpers.toBool(report.AllowExcel) && results.length > 0 && (
                            <Button
                                type="primary"
                                icon={<FileExcelOutlined />}
                                onClick={exportToExcel}
                                style={STYLES.successButton}
                            >
                                خروجی Excel
                            </Button>
                        )}
                    </Space>
                }
                style={STYLES.card}
            >
                {executing ? (
                    <div style={{ textAlign: 'center', padding: 60 }}>
                        <Spin indicator={<LoadingOutlined style={{ fontSize: 48, color: THEME.primary }} spin />} />
                        <div style={{ marginTop: 16 }}><Text type="secondary">در حال اجرای گزارش...</Text></div>
                    </div>
                ) : !hasExecuted ? (
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={hasParameters ? 'پارامترها را وارد کنید و روی "نمایش گزارش" کلیک کنید' : 'در حال بارگذاری گزارش...'}
                    />
                ) : (
                    <DataGrid
                        columns={columns}
                        dataSource={results}
                        loading={executing}
                    />
                )}
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}