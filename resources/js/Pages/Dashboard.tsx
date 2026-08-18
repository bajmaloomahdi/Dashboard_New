import { Card, Row, Col, Typography, Space, Tag, Avatar, List, Progress } from 'antd';
import {
    UserOutlined,
    ShoppingCartOutlined,
    DollarOutlined,
    RiseOutlined,
    FallOutlined,
    ShopOutlined,
    TeamOutlined,
    FileTextOutlined,
    TrophyOutlined,
    ClockCircleOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import MainLayout from '../Layouts/MainLayout';
import { THEME, STYLES, columnHelpers } from '../theme';
import {
    LineChart,
    Line,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
    Area,
    AreaChart,
} from 'recharts';

const { Title, Text } = Typography;

// ============================================
// داده‌های نمونه (fake data)
// ============================================
const kpiData = [
    {
        title: 'فروش امروز',
        value: 45320000,
        prefix: <DollarOutlined />,
        color: '#10B981',
        bgColor: '#D1FAE5',
        growth: 8.3,
        suffix: 'تومان',
    },
    {
        title: 'فروش این ماه',
        value: 1250000000,
        prefix: <ShoppingCartOutlined />,
        color: '#3B82F6',
        bgColor: '#DBEAFE',
        growth: 12.5,
        suffix: 'تومان',
    },
    {
        title: 'کل مشتریان',
        value: 1284,
        prefix: <TeamOutlined />,
        color: '#8B5CF6',
        bgColor: '#EDE9FE',
        growth: 5.2,
        suffix: 'نفر',
    },
    {
        title: 'تعداد فاکتور',
        value: 156,
        prefix: <FileTextOutlined />,
        color: '#F59E0B',
        bgColor: '#FEF3C7',
        growth: -2.4,
        suffix: 'فاکتور',
    },
];

// نمودار فروش ۳۰ روز اخیر
const salesChartData = [
    { day: '1', sales: 25000000, target: 30000000 },
    { day: '5', sales: 32000000, target: 30000000 },
    { day: '10', sales: 28000000, target: 30000000 },
    { day: '15', sales: 45000000, target: 35000000 },
    { day: '20', sales: 38000000, target: 35000000 },
    { day: '25', sales: 52000000, target: 40000000 },
    { day: '30', sales: 45320000, target: 40000000 },
];

// پرفروش‌ترین کالاها
const topProducts = [
    { name: 'بلیستر 20 عددی زهراوی', sales: 15000000, count: 45 },
    { name: 'قرص استامینوفن 500', sales: 12500000, count: 38 },
    { name: 'شربت آنتی‌بیوتیک', sales: 10200000, count: 32 },
    { name: 'کپسول ویتامین C', sales: 8500000, count: 28 },
    { name: 'قطره چشمی', sales: 6300000, count: 22 },
];

// پرفروش‌ترین ویزیتورها
const topVisitors = [
    { name: 'علی محمدی', sales: 45000000, orders: 25, avatar: 'ع' },
    { name: 'حسن رضایی', sales: 38000000, orders: 22, avatar: 'ح' },
    { name: 'محمد کریمی', sales: 32000000, orders: 18, avatar: 'م' },
    { name: 'رضا احمدی', sales: 28000000, orders: 16, avatar: 'ر' },
    { name: 'مهدی حسینی', sales: 25000000, orders: 14, avatar: 'م' },
];

// آخرین فعالیت‌ها
const recentActivities = [
    { title: 'فاکتور جدید', desc: 'فاکتور #12345 به مبلغ 2,500,000', time: '5 دقیقه پیش', color: '#10B981', icon: <ShoppingCartOutlined /> },
    { title: 'مشتری جدید', desc: 'شرکت پارس دارو به سیستم اضافه شد', time: '30 دقیقه پیش', color: '#3B82F6', icon: <TeamOutlined /> },
    { title: 'گزارش تولید شد', desc: 'گزارش فروش ماهانه آماده است', time: '1 ساعت پیش', color: '#8B5CF6', icon: <FileTextOutlined /> },
    { title: 'فاکتور جدید', desc: 'فاکتور #12344 به مبلغ 1,800,000', time: '2 ساعت پیش', color: '#F59E0B', icon: <ShoppingCartOutlined /> },
];

// ============================================
// کامپوننت اصلی
// ============================================
export default function Dashboard() {
    const { auth, company } = usePage().props as any;

    return (
        <MainLayout>
            {/* هدر خوشامدگویی */}
            <Card
                style={{
                    marginBottom: 24,
                    ...STYLES.card,
                    background: THEME.primaryGradient,
                    border: 'none',
                }}
                styles={{ body: { padding: 24 } }}
            >
                <Row align="middle" justify="space-between">
                    <Col>
                        <Space size="middle">
                            <Avatar
                                size={64}
                                style={{
                                    background: 'rgba(255,255,255,0.2)',
                                    border: '3px solid rgba(255,255,255,0.4)',
                                    fontSize: 28,
                                    fontWeight: 'bold',
                                }}
                            >
                                {auth?.user?.FirstName?.charAt(0) || 'م'}
                            </Avatar>
                            <div>
                                <Title level={3} style={{ color: '#fff', margin: 0 }}>
                                    سلام {auth?.user?.FullName || 'کاربر'} 👋
                                </Title>
                                <Text style={{ color: 'rgba(255,255,255,0.9)', fontSize: 14 }}>
                                    خلاصه فعالیت‌های امروز شما
                                </Text>
                            </div>
                        </Space>
                    </Col>
                    <Col>
                        {/* لوگوی شرکت به‌جای ایکون نمودار */}
                        {company?.LogoMimeType ? (
                            <img
                                src={`/company/logo?t=${Date.now()}`}
                                alt="لوگوی شرکت"
                                style={{
                                    width: 80,
                                    height: 80,
                                    objectFit: 'contain',
                                    background: 'rgba(255,255,255,0.9)',
                                    borderRadius: 16,
                                    padding: 6,
                                    boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
                                }}
                            />
                        ) : (
                            <div style={{ fontSize: 60 }}>📊</div>
                        )}
                    </Col>
                </Row>
            </Card>

            {/* KPI Cards - ۴ کارت */}
            <Row gutter={[16, 16]} style={{ marginBottom: 24 }}>
                {kpiData.map((kpi, index) => (
                    <Col xs={24} sm={12} lg={6} key={index}>
                        <Card
                            hoverable
                            style={{
                                ...STYLES.card,
                                borderTop: `4px solid ${kpi.color}`,
                            }}
                        >
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                                <div style={{ flex: 1 }}>
                                    <Text type="secondary" style={{ fontSize: 13 }}>
                                        {kpi.title}
                                    </Text>
                                    <div style={{ marginTop: 8, marginBottom: 8 }}>
                                        <Text strong style={{
                                            fontSize: 22,
                                            color: kpi.color,
                                            fontFamily: 'monospace',
                                        }}>
                                            {columnHelpers.formatNumber(kpi.value)}
                                        </Text>
                                        <Text type="secondary" style={{ fontSize: 12, marginRight: 4 }}>
                                            {kpi.suffix}
                                        </Text>
                                    </div>
                                    <Space size={4}>
                                        {kpi.growth >= 0 ? (
                                            <RiseOutlined style={{ color: THEME.success, fontSize: 12 }} />
                                        ) : (
                                            <FallOutlined style={{ color: THEME.error, fontSize: 12 }} />
                                        )}
                                        <Text style={{
                                            fontSize: 12,
                                            color: kpi.growth >= 0 ? THEME.success : THEME.error,
                                            fontWeight: 600,
                                        }}>
                                            {Math.abs(kpi.growth)}%
                                        </Text>
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            نسبت به دیروز
                                        </Text>
                                    </Space>
                                </div>
                                <div style={{
                                    width: 48,
                                    height: 48,
                                    borderRadius: 12,
                                    background: kpi.bgColor,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    color: kpi.color,
                                    fontSize: 22,
                                }}>
                                    {kpi.prefix}
                                </div>
                            </div>
                        </Card>
                    </Col>
                ))}
            </Row>

            {/* نمودارها */}
            <Row gutter={[16, 16]} style={{ marginBottom: 24 }}>
                {/* نمودار خطی فروش */}
                <Col xs={24} lg={16}>
                    <Card
                        title={
                            <Space>
                                <RiseOutlined style={{ color: THEME.primary }} />
                                <span>نمودار فروش ۳۰ روز اخیر</span>
                            </Space>
                        }
                        extra={
                            <Space>
                                <Tag color="green">هدف: 40M</Tag>
                                <Tag color="blue">میانگین: 38M</Tag>
                            </Space>
                        }
                        style={STYLES.card}
                    >
                        <ResponsiveContainer width="100%" height={300}>
                            <AreaChart data={salesChartData}>
                                <defs>
                                    <linearGradient id="colorSales" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor={THEME.primary} stopOpacity={0.8} />
                                        <stop offset="95%" stopColor={THEME.primary} stopOpacity={0.1} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                                <XAxis
                                    dataKey="day"
                                    label={{ value: 'روز ماه', position: 'insideBottom', offset: -5 }}
                                    style={{ fontSize: 11 }}
                                />
                                <YAxis
                                    tickFormatter={(value) => `${value / 1000000}M`}
                                    style={{ fontSize: 11 }}
                                />
                                <Tooltip
                                    formatter={(value: number) => columnHelpers.formatNumber(value) + ' تومان'}
                                    contentStyle={{
                                        borderRadius: 8,
                                        border: 'none',
                                        boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
                                    }}
                                />
                                <Legend />
                                <Area
                                    type="monotone"
                                    dataKey="sales"
                                    name="فروش واقعی"
                                    stroke={THEME.primary}
                                    fillOpacity={1}
                                    fill="url(#colorSales)"
                                    strokeWidth={3}
                                />
                                <Line
                                    type="monotone"
                                    dataKey="target"
                                    name="هدف"
                                    stroke={THEME.success}
                                    strokeWidth={2}
                                    strokeDasharray="5 5"
                                    dot={false}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </Card>
                </Col>

                {/* نمودار میله‌ای Top Products */}
                <Col xs={24} lg={8}>
                    <Card
                        title={
                            <Space>
                                <TrophyOutlined style={{ color: THEME.warning }} />
                                <span>پرفروش‌ترین کالاها</span>
                            </Space>
                        }
                        style={STYLES.card}
                    >
                        <ResponsiveContainer width="100%" height={300}>
                            <BarChart data={topProducts} layout="vertical">
                                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                                <XAxis
                                    type="number"
                                    tickFormatter={(value) => `${value / 1000000}M`}
                                    style={{ fontSize: 10 }}
                                />
                                <YAxis
                                    type="category"
                                    dataKey="name"
                                    width={100}
                                    style={{ fontSize: 10 }}
                                />
                                <Tooltip
                                    formatter={(value: number) => columnHelpers.formatNumber(value) + ' تومان'}
                                    contentStyle={{
                                        borderRadius: 8,
                                        border: 'none',
                                        boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
                                    }}
                                />
                                <Bar dataKey="sales" fill={THEME.primary} radius={[0, 8, 8, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </Card>
                </Col>
            </Row>

            {/* لیست‌ها */}
            <Row gutter={[16, 16]}>
                {/* پرفروش‌ترین ویزیتورها */}
                <Col xs={24} lg={12}>
                    <Card
                        title={
                            <Space>
                                <UserOutlined style={{ color: THEME.primary }} />
                                <span>پرفروش‌ترین ویزیتورها</span>
                            </Space>
                        }
                        extra={<Text type="secondary" style={{ fontSize: 12 }}>این ماه</Text>}
                        style={STYLES.card}
                    >
                        {topVisitors.map((visitor, index) => (
                            <div key={index} style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 12,
                                padding: '12px 0',
                                borderBottom: index < topVisitors.length - 1 ? '1px solid #F3F4F6' : 'none',
                            }}>
                                <div style={{
                                    width: 32,
                                    height: 32,
                                    borderRadius: '50%',
                                    background: index === 0 ? '#FEF3C7' : index === 1 ? '#E5E7EB' : '#FED7AA',
                                    color: index === 0 ? '#D97706' : index === 1 ? '#4B5563' : '#EA580C',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontWeight: 'bold',
                                    fontSize: 14,
                                }}>
                                    {index + 1}
                                </div>
                                <Avatar style={{ background: THEME.primaryGradient }}>
                                    {visitor.avatar}
                                </Avatar>
                                <div style={{ flex: 1 }}>
                                    <Text strong>{visitor.name}</Text>
                                    <div>
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            {visitor.orders} سفارش
                                        </Text>
                                    </div>
                                </div>
                                <div style={{ textAlign: 'left' }}>
                                    <Text strong style={{ color: THEME.success, fontFamily: 'monospace' }}>
                                        {columnHelpers.formatNumber(visitor.sales)}
                                    </Text>
                                    <div>
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            تومان
                                        </Text>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </Card>
                </Col>

                {/* آخرین فعالیت‌ها */}
                <Col xs={24} lg={12}>
                    <Card
                        title={
                            <Space>
                                <ClockCircleOutlined style={{ color: THEME.warning }} />
                                <span>آخرین فعالیت‌ها</span>
                            </Space>
                        }
                        extra={<a href="#">مشاهده همه</a>}
                        style={STYLES.card}
                    >
                        {recentActivities.map((activity, index) => (
                            <div key={index} style={{
                                display: 'flex',
                                alignItems: 'flex-start',
                                gap: 12,
                                padding: '12px 0',
                                borderBottom: index < recentActivities.length - 1 ? '1px solid #F3F4F6' : 'none',
                            }}>
                                <div style={{
                                    width: 40,
                                    height: 40,
                                    borderRadius: 10,
                                    background: activity.color + '20',
                                    color: activity.color,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontSize: 18,
                                    flexShrink: 0,
                                }}>
                                    {activity.icon}
                                </div>
                                <div style={{ flex: 1 }}>
                                    <Text strong>{activity.title}</Text>
                                    <div>
                                        <Text type="secondary" style={{ fontSize: 12 }}>
                                            {activity.desc}
                                        </Text>
                                    </div>
                                </div>
                                <Text type="secondary" style={{ fontSize: 11, whiteSpace: 'nowrap' }}>
                                    {activity.time}
                                </Text>
                            </div>
                        ))}
                    </Card>
                </Col>
            </Row>
        </MainLayout>
    );
}