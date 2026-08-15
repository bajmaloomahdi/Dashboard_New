import { useState, useEffect, useMemo } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Tag,
    Switch,
    Typography,
    Row,
    Col,
    Statistic,
    Alert,
    Avatar,
} from 'antd';
import {
    ArrowLeftOutlined,
    SaveOutlined,
    IdcardOutlined,
    SearchOutlined,
    CheckCircleOutlined,
    StopOutlined,
    CheckSquareOutlined,
    BorderOutlined,
    TeamOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Position {
    PositionID: number;
    PositionCode: string;
    PositionName: string;
    UnitID: number;
    UnitName: string;
    HasPosition: boolean | number;
}

interface User {
    UserID: number;
    FullName: string;
    UserName: string;
    UserCode: string;
}

export default function UserPositions() {
    const { user, positions, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [selectedPositionIds, setSelectedPositionIds] = useState<Set<number>>(new Set());
    const [initialPositionIds, setInitialPositionIds] = useState<Set<number>>(new Set());
    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const ids = new Set<number>(
            positions
                .filter((p: Position) => columnHelpers.toBool(p.HasPosition))
                .map((p: Position) => p.PositionID)
        );
        setSelectedPositionIds(ids);
        setInitialPositionIds(ids);
    }, [positions]);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const filteredPositions = useMemo(() => {
        if (!searchText) return positions;
        const search = searchText.toLowerCase();
        return positions.filter((p: Position) =>
            p.PositionName?.toLowerCase().includes(search) ||
            p.PositionCode?.toLowerCase().includes(search) ||
            p.UnitName?.toLowerCase().includes(search)
        );
    }, [positions, searchText]);

    const hasChanges = useMemo(() => {
        if (selectedPositionIds.size !== initialPositionIds.size) return true;
        for (const id of selectedPositionIds) {
            if (!initialPositionIds.has(id)) return true;
        }
        return false;
    }, [selectedPositionIds, initialPositionIds]);

    const handleTogglePosition = (positionId: number, checked: boolean) => {
        const newSet = new Set(selectedPositionIds);
        if (checked) newSet.add(positionId);
        else newSet.delete(positionId);
        setSelectedPositionIds(newSet);
    };

    const handleSelectAll = (selectAll: boolean) => {
        const newSet = new Set(selectedPositionIds);
        filteredPositions.forEach((p: Position) => {
            if (selectAll) newSet.add(p.PositionID);
            else newSet.delete(p.PositionID);
        });
        setSelectedPositionIds(newSet);
    };

    const handleSave = () => {
        setSaving(true);
        router.post(`/users/${user.UserID}/positions`, {
            position_ids: Array.from(selectedPositionIds),
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash', 'positions'],
            onFinish: () => setSaving(false),
        });
    };

    const allSelected = filteredPositions.length > 0 &&
        filteredPositions.every((p: Position) => selectedPositionIds.has(p.PositionID));
    const someSelected = filteredPositions.some((p: Position) => selectedPositionIds.has(p.PositionID));

    const customColumns: ColumnsType<Position> = [
        {
            title: 'تخصیص',
            key: 'assign',
            width: 100,
            align: 'center',
            render: (_, record: Position) => (
                <Switch
                    checked={selectedPositionIds.has(record.PositionID)}
                    onChange={(checked) => handleTogglePosition(record.PositionID, checked)}
                    checkedChildren={<CheckCircleOutlined />}
                    unCheckedChildren={<StopOutlined />}
                />
            ),
        },
        {
            title: 'کد',
            dataIndex: 'PositionCode',
            key: 'PositionCode',
            width: 100,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'سمت',
            key: 'position',
            align: 'center',
            render: (_, record: Position) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 250,
                    justifyContent: 'flex-start',
                }}>
                    <div style={STYLES.iconBox}>
                        <IdcardOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.PositionName}</Text>
                    </div>
                </div>
            ),
        },
        {
            title: 'واحد',
            dataIndex: 'UnitName',
            key: 'UnitName',
            width: 160,
            align: 'center',
            render: (name: string) =>
                name ? <Tag color="geekblue" style={{ borderRadius: 6 }}>{name}</Tag> : <Text type="secondary">—</Text>,
        },
    ];

    return (
        <MainLayout>
            {/* هدر */}
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Space>
                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit('/users')}
                            style={{ borderColor: THEME.primary, color: THEME.primary }}
                        >
                            بازگشت به لیست
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <IdcardOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                مدیریت سمت‌های کاربر
                            </Title>
                            <Text type="secondary">
                                کاربر: <Text strong style={{ color: THEME.primary }}>{user.FullName || user.UserName}</Text>
                                {' '}
                                (@{user.UserName})
                            </Text>
                        </div>
                    </Space>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<SaveOutlined />}
                        size="large"
                        loading={saving}
                        disabled={!hasChanges}
                        onClick={handleSave}
                        style={hasChanges ? STYLES.primaryButton : undefined}
                    >
                        ذخیره تغییرات
                    </Button>
                </Col>
            </Row>

            {hasChanges && (
                <Alert
                    message="تغییرات ذخیره نشده‌اند"
                    description="برای اعمال تغییرات، دکمه ذخیره را کلیک کنید."
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16, borderRadius: 8 }}
                />
            )}

            {/* آمار */}
            <Row gutter={16} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.primary}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>کل سمت‌ها</span>}
                            value={positions.length}
                            prefix={<IdcardOutlined style={{ color: THEME.primary }} />}
                            valueStyle={{ color: THEME.primary, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.success}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>سمت‌های فعلی کاربر</span>}
                            value={selectedPositionIds.size}
                            prefix={<CheckCircleOutlined style={{ color: THEME.success }} />}
                            valueStyle={{ color: THEME.success, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={24} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.warning}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>سمت‌های بدون تخصیص</span>}
                            value={positions.filter((p: Position) => !selectedPositionIds.has(p.PositionID)).length}
                            prefix={<StopOutlined style={{ color: THEME.warning }} />}
                            valueStyle={{ color: THEME.warning, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* جدول */}
            <Card style={STYLES.card}>
                <Row gutter={[16, 16]} align="middle" style={{ marginBottom: 16 }}>
                    <Col xs={24} md={14}>
                        <Input
                            placeholder="جستجوی زنده در سمت‌ها و واحدها..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} md={10}>
                        <Space>
                            <Button
                                icon={<CheckSquareOutlined />}
                                onClick={() => handleSelectAll(true)}
                                disabled={allSelected}
                                style={{
                                    background: THEME.success,
                                    borderColor: THEME.success,
                                    color: '#fff',
                                }}
                                size="large"
                            >
                                انتخاب همه
                            </Button>
                            <Button
                                icon={<BorderOutlined />}
                                onClick={() => handleSelectAll(false)}
                                disabled={!someSelected}
                                danger
                                size="large"
                            >
                                برداشتن همه
                            </Button>
                        </Space>
                    </Col>
                </Row>

                <DataGrid
                    columns={[]}
                    dataSource={filteredPositions}
                    customColumns={customColumns}
                    rowKey="PositionID"
                    showRowNumber={false}
                    showColumnSearch={false}
                    pageSize={15}
                />
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