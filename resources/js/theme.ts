/**
 * سیستم تم یکپارچه پروژه
 */

export const THEME = {
    primary: '#667eea',
    primaryDark: '#764ba2',
    primaryLight: '#F5F3FF',
    primaryLighter: '#FAF5FF',

    primaryGradient: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
    primaryGradientLight: 'linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%)',
    successGradient: 'linear-gradient(135deg, #059669 0%, #10B981 100%)',

    success: '#10B981',
    error: '#EF4444',
    warning: '#F59E0B',
    info: '#3B82F6',

    textPrimary: '#1F2937',
    textSecondary: '#6B7280',
    textLight: '#9CA3AF',

    bgWhite: '#FFFFFF',
    bgLight: '#FAFAFA',
    bgLighter: '#F9FAFB',
    bgHover: '#F5F3FF',

    border: '#E5E7EB',
    borderLight: '#F3F4F6',
    borderPrimary: '#DDD6FE',

    excel: {
        headerBg: 'FF667EEA',
        headerText: 'FFFFFFFF',
        evenRowBg: 'FFF5F3FF',
        oddRowBg: 'FFFFFFFF',
        rowNumberBgEven: 'FFDDD6FE',
        rowNumberBgOdd: 'FFEDE9FE',
        rowNumberText: 'FF4C1D95',
        dataText: 'FF1F2937',
        borderColor: 'FFDDD6FE',
        headerBorderColor: 'FF4C1D95',
    },
};

export const STYLES = {
    card: {
        borderRadius: 12,
        border: 'none',
        boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
    },

    // کارت فیلترها - همرنگ هدر جدول
    filterCard: {
        borderRadius: 12,
        border: 'none',
        boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
        background: '#EEEBFB',
    },

    cardHeader: {
        background: 'linear-gradient(135deg, #FAFAFA 0%, #F5F5F5 100%)',
        borderBottom: '2px solid #F3F4F6',
        padding: '16px 20px',
    },

    iconBox: {
        width: 40,
        height: 40,
        borderRadius: 10,
        background: THEME.primaryGradient,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        boxShadow: '0 2px 8px rgba(102, 126, 234, 0.3)',
    },

    iconBoxLarge: {
        width: 56,
        height: 56,
        borderRadius: 12,
        background: THEME.primaryGradient,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        boxShadow: '0 4px 12px rgba(102, 126, 234, 0.3)',
    },

    rowNumber: {
        width: 32,
        height: 32,
        borderRadius: '50%',
        background: THEME.primaryGradient,
        color: '#fff',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12,
        fontWeight: 'bold' as const,
        boxShadow: '0 2px 4px rgba(102, 126, 234, 0.3)',
    },

    primaryButton: {
        background: THEME.primaryGradient,
        border: 'none',
        borderRadius: 8,
        fontWeight: 600,
        boxShadow: '0 4px 12px rgba(102, 126, 234, 0.3)',
    },

    successButton: {
        background: THEME.successGradient,
        border: 'none',
        borderRadius: 8,
        fontWeight: 600,
        boxShadow: '0 4px 12px rgba(16, 185, 129, 0.3)',
    },

    codeBadge: {
        fontFamily: 'monospace',
        direction: 'ltr' as const,
        display: 'inline-block',
        background: '#EDE9FE',
        color: '#5B21B6',
        padding: '4px 10px',
        borderRadius: 6,
        fontSize: 12,
        fontWeight: 600,
        border: '1px solid #DDD6FE',
    },

    dateBadge: {
        fontFamily: 'monospace',
        direction: 'ltr' as const,
        display: 'inline-block',
        background: '#F5F3FF',
        color: '#6D28D9',
        padding: '4px 10px',
        borderRadius: 6,
        fontSize: 12,
        fontWeight: 500,
        border: '1px solid #DDD6FE',
    },

    numberBadge: {
        fontFamily: 'monospace',
        direction: 'ltr' as const,
        display: 'inline-block',
        color: '#065F46',
        fontSize: 13,
        fontWeight: 600,
    },
};

export const TABLE_CLASS_NAME = 'unified-table';

/**
 * CSS استاندارد برای همه جدول‌ها - هدر بنفش خیلی روشن
 */
export const TABLE_CSS = [
    '.unified-table .ant-table {',
    '    border-radius: 12px;',
    '    overflow: hidden;',
    '}',
    // هدر بنفش خیلی روشن
    '.unified-table .ant-table-thead > tr > th {',
    '    background: #EEEBFB !important;',
    '    color: #1F2937 !important;',
    '    font-weight: 600 !important;',
    '    border-bottom: 2px solid #C7BFEF !important;',
    '    padding: 12px 8px !important;',
    '    font-size: 13px;',
    '}',
    // خط جدا کننده عمودی بین ستون‌های هدر
    '.unified-table .ant-table-thead > tr > th:not(:last-child) {',
    '    border-left: 1px solid #C7BFEF !important;',
    '}',
    '.unified-table .ant-table-thead > tr > th::before {',
    '    display: none !important;',
    '}',
    // input سرچ در هدر
    '.unified-table .ant-table-thead > tr > th .ant-input,',
    '.unified-table .ant-table-thead > tr > th .ant-input-affix-wrapper {',
    '    background: #FFFFFF;',
    '    border: 1px solid #C7BFEF;',
    '}',
    // ردیف‌های زبرا
    '.unified-table .row-even {',
    '    background: #FAFAFA;',
    '}',
    '.unified-table .row-odd {',
    '    background: #FFFFFF;',
    '}',
    // hover ملایم
    '.unified-table .ant-table-tbody > tr:hover > td {',
    '    background: #F5F3FF !important;',
    '    transition: background 0.2s;',
    '}',
    // سلول‌ها
    '.unified-table .ant-table-tbody > tr > td {',
    '    padding: 12px !important;',
    '    border-color: #F3F4F6 !important;',
    '    font-size: 13px;',
    '}',
    '.unified-table .ant-table-cell-fix-right {',
    '    background: inherit !important;',
    '}',
    // Pagination
    '.unified-table .ant-pagination {',
    '    padding: 16px 0 8px !important;',
    '    margin: 16px 0 0 !important;',
    '    border-top: 2px solid #F3F4F6;',
    '}',
    '.unified-table .ant-pagination-item-active {',
    '    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;',
    '    border-color: #667eea !important;',
    '}',
    '.unified-table .ant-pagination-item-active a {',
    '    color: #fff !important;',
    '}',
    '.unified-table .ant-pagination-item:hover {',
    '    border-color: #667eea !important;',
    '}',
    '.unified-table .ant-pagination-item:hover a {',
    '    color: #667eea !important;',
    '}',
].join('\n');

export const columnHelpers = {
    isDateString: (value: any): boolean => {
        if (typeof value !== 'string' && typeof value !== 'number') return false;
        const str = String(value);
        if (/^\d{8}$/.test(str)) return true;
        return /^\d{4}[/-]\d{1,2}[/-]\d{1,2}/.test(str);
    },

    isDateColumn: (columnName: string, sampleValue: any): boolean => {
        const lowerName = columnName.toLowerCase();
        const dateKeywords = ['تاریخ', 'date', 'زمان', 'time'];
        if (dateKeywords.some(k => lowerName.includes(k))) return true;
        if (columnHelpers.isDateString(sampleValue)) return true;
        return false;
    },

    isCodeColumn: (columnName: string): boolean => {
        const lowerName = columnName.toLowerCase();
        const codeKeywords = ['کد', 'شماره', 'code', 'id', 'شناسه'];
        return codeKeywords.some(k => lowerName.includes(k));
    },

    isNumericValue: (value: any): boolean => {
        if (typeof value === 'number') return true;
        if (typeof value === 'string') {
            const cleaned = value.replace(/,/g, '').trim();
            return !isNaN(parseFloat(cleaned)) && cleaned !== '' && !columnHelpers.isDateString(value);
        }
        return false;
    },

    formatDate: (value: any): string => {
        if (!value) return '';
        const str = String(value).trim();

        if (/^\d{8}$/.test(str)) {
            return str.substring(0, 4) + '/' + str.substring(4, 6) + '/' + str.substring(6, 8);
        }
        if (/^\d{6}$/.test(str)) {
            return str.substring(0, 4) + '/' + str.substring(4, 6);
        }

        const cleanDate = str.split(' ')[0].split('T')[0];
        return cleanDate.replace(/-/g, '/');
    },

    formatNumber: (value: any): string => {
        if (typeof value === 'number') return Math.round(value).toLocaleString('en-US');
        if (typeof value === 'string') {
            const cleaned = value.replace(/,/g, '').trim();
            const num = parseFloat(cleaned);
            if (!isNaN(num) && cleaned !== '') return Math.round(num).toLocaleString('en-US');
        }
        return String(value);
    },

    toBool: (value: any): boolean => Number(value) === 1,
};