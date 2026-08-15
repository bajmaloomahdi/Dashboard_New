import { Button, Card, Typography, Space } from 'antd';
import { RocketOutlined } from '@ant-design/icons';

const { Title, Paragraph } = Typography;

export default function Welcome() {
    return (
        <div style={{ padding: '50px', textAlign: 'center' }}>
            <Card style={{ maxWidth: 600, margin: '0 auto' }}>
                <Space orientation="vertical" size="large">
                    <RocketOutlined style={{ fontSize: 64, color: '#1890ff' }} />
                    <Title level={2}>به داشبورد خوش آمدید! 🎉</Title>
                    <Paragraph>
                        لاراول + Inertia.js + React + Ant Design با موفقیت نصب شد.
                    </Paragraph>
                    <Button type="primary" size="large">
                        شروع کنیم
                    </Button>
                </Space>
            </Card>
        </div>
    );
}