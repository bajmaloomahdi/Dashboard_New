import { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import {
    PlayCircleFilled,
    CheckCircleFilled,
    UserOutlined,
    BranchesOutlined,
} from '@ant-design/icons';
import { THEME } from '../../../../theme';

export interface DesignerNodeData {
    label: string;
    description: string | null;
    [key: string]: unknown;
}

const baseCardStyle: React.CSSProperties = {
    minWidth: 160,
    borderRadius: 10,
    padding: '10px 14px',
    fontSize: 13,
    fontWeight: 600,
    color: '#fff',
    textAlign: 'center',
    boxShadow: '0 2px 8px rgba(0,0,0,0.12)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
};

const handleStyle: React.CSSProperties = { width: 10, height: 10, background: '#fff', border: `2px solid ${THEME.textLight}` };

function StartNode({ data, selected }: NodeProps) {
    const d = data as DesignerNodeData;
    return (
        <div
            style={{
                ...baseCardStyle,
                background: THEME.success,
                borderRadius: 24,
                outline: selected ? `2px solid ${THEME.primary}` : 'none',
                outlineOffset: 2,
            }}
        >
            <PlayCircleFilled />
            <span>{d.label}</span>
            <Handle type="source" position={Position.Left} style={handleStyle} />
        </div>
    );
}

function EndNode({ data, selected }: NodeProps) {
    const d = data as DesignerNodeData;
    return (
        <div
            style={{
                ...baseCardStyle,
                background: THEME.textPrimary,
                borderRadius: 24,
                outline: selected ? `2px solid ${THEME.primary}` : 'none',
                outlineOffset: 2,
            }}
        >
            <CheckCircleFilled />
            <span>{d.label}</span>
            <Handle type="target" position={Position.Right} style={handleStyle} />
        </div>
    );
}

function TaskNode({ data, selected }: NodeProps) {
    const d = data as DesignerNodeData;
    return (
        <div
            style={{
                ...baseCardStyle,
                background: THEME.primaryGradient,
                outline: selected ? `2px solid ${THEME.textPrimary}` : 'none',
                outlineOffset: 2,
            }}
        >
            <Handle type="target" position={Position.Right} style={handleStyle} />
            <UserOutlined />
            <span>{d.label}</span>
            <Handle type="source" position={Position.Left} style={handleStyle} />
        </div>
    );
}

function ConditionNode({ data, selected }: NodeProps) {
    const d = data as DesignerNodeData;
    return (
        <div
            style={{
                ...baseCardStyle,
                background: THEME.warning,
                borderRadius: 10,
                transform: 'rotate(0deg)',
                outline: selected ? `2px solid ${THEME.textPrimary}` : 'none',
                outlineOffset: 2,
            }}
        >
            <Handle type="target" position={Position.Right} style={handleStyle} />
            <BranchesOutlined />
            <span>{d.label}</span>
            <Handle type="source" position={Position.Left} id="a" style={{ ...handleStyle, top: '35%' }} />
            <Handle type="source" position={Position.Left} id="b" style={{ ...handleStyle, top: '65%' }} />
        </div>
    );
}

export const designerNodeTypes = {
    start: memo(StartNode),
    task: memo(TaskNode),
    condition: memo(ConditionNode),
    end: memo(EndNode),
};

export const NODE_TYPE_META: Record<string, { label: string; icon: JSX.Element; color: string; stepType: string }> = {
    start: { label: 'شروع', icon: <PlayCircleFilled />, color: THEME.success, stepType: 'START' },
    task: { label: 'وظیفه / فرایند', icon: <UserOutlined />, color: THEME.primary, stepType: 'USER_TASK' },
    condition: { label: 'شرط', icon: <BranchesOutlined />, color: THEME.warning, stepType: 'CONDITION' },
    end: { label: 'پایان', icon: <CheckCircleFilled />, color: THEME.textPrimary, stepType: 'END' },
};
