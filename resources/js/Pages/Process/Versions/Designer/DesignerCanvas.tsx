import { useCallback, useMemo, useRef, useState } from 'react';
import {
    ReactFlow,
    ReactFlowProvider,
    Background,
    Controls,
    MiniMap,
    useNodesState,
    useEdgesState,
    addEdge,
    MarkerType,
    type Node,
    type Edge,
    type Connection,
    type NodeChange,
    type EdgeChange,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { Button, Space, Input, InputNumber, Select, Typography, Empty, Tooltip, Popconfirm, Divider, Checkbox, Tag } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { THEME, STYLES } from '../../../../theme';
import { designerNodeTypes, NODE_TYPE_META, type DesignerNodeData } from './nodeTypes';
import RuleBuilder from './RuleBuilder';
import type { ConditionField } from './ruleTypes';
import {
    ASSIGNEE_TYPES,
    type AssigneeType,
    type AssignmentDraft,
    type GraphDraft,
    type RuleGroup,
    type StepDraft,
    type StepType,
    type TransitionDraft,
} from '../versionEditor';

const { Text, Title } = Typography;

interface LookupOption {
    UserID?: number; RoleID?: number; PositionID?: number; UnitID?: number;
    FullName?: string; RoleName?: string; PositionName?: string; UnitName?: string;
}

interface DesignerCanvasProps {
    graph: GraphDraft;
    readOnly: boolean;
    onChange: (graph: GraphDraft) => void;
    users: LookupOption[];
    roles: LookupOption[];
    positions: LookupOption[];
    units: LookupOption[];
    /** فیلدهایِ شرطِ همین Definition — برایِ Rule Builderِ گذارهایِ خروجیِ Stepِ CONDITION */
    conditionFields: ConditionField[];
}

type PaletteType = keyof typeof NODE_TYPE_META;

function nextCode(existing: string[], type: PaletteType): string {
    if (type === 'start') return existing.includes('START') ? `START_${existing.length + 1}` : 'START';
    if (type === 'end' && !existing.includes('END')) return 'END';
    const prefix = type === 'task' ? 'TASK' : type === 'end' ? 'END' : 'COND';
    let n = 1;
    while (existing.includes(`${prefix}_${n}`)) n++;
    return `${prefix}_${n}`;
}

function nextTransitionCode(existing: string[]): string {
    let n = 1;
    while (existing.includes(`T${n}`)) n++;
    return `T${n}`;
}

function stepToNode(step: StepDraft, index: number): Node<DesignerNodeData> {
    const type = step.stepType === 'START' ? 'start' : step.stepType === 'END' ? 'end' : step.stepType === 'CONDITION' ? 'condition' : 'task';
    return {
        id: step.code,
        type,
        position: { x: step.positionX ?? 120 + (index % 4) * 220, y: step.positionY ?? 80 + Math.floor(index / 4) * 140 },
        data: { label: step.name, description: step.description, dueDurationHours: step.dueDurationHours },
    };
}

function transitionToEdge(t: TransitionDraft): Edge {
    return {
        id: t.code,
        source: t.fromStepCode,
        target: t.toStepCode,
        label: t.label ?? undefined,
        markerEnd: { type: MarkerType.ArrowClosed, color: THEME.textLight },
        style: { stroke: THEME.textLight, strokeWidth: 1.6 },
        labelBgStyle: { fill: '#fff' },
        labelStyle: { fontSize: 11, fontWeight: 600 },
    };
}

function newStepDraft(code: string, name: string, stepType: StepType, position: { x: number; y: number }): StepDraft {
    return {
        code,
        name,
        stepType,
        assignPolicy: 'ANY',
        requiredApprovals: null,
        allowForward: false,
        forwardMax: null,
        allowDelegation: true,
        sortOrder: 0,
        positionX: Math.round(position.x),
        positionY: Math.round(position.y),
        description: null,
        dueDurationHours: null,
    };
}

function newTransitionDraft(code: string, fromStepCode: string, toStepCode: string): TransitionDraft {
    return {
        code,
        fromStepCode,
        toStepCode,
        triggerActionCode: null,
        priority: 100,
        isDefault: false,
        label: null,
        conditionExpression: null,
        ruleJson: null,
    };
}

function newAssignmentDraft(stepCode: string, sortOrder: number): AssignmentDraft {
    return { stepCode, assigneeType: 'USER', refId: null, refExpression: null, sortOrder, isBackup: false };
}

function lookupOptions(
    type: AssigneeType,
    lists: { users: LookupOption[]; roles: LookupOption[]; positions: LookupOption[]; units: LookupOption[] }
): { value: number | undefined; label: string | undefined }[] {
    switch (type) {
        case 'USER': return lists.users.map((u) => ({ value: u.UserID, label: u.FullName }));
        case 'ROLE': return lists.roles.map((r) => ({ value: r.RoleID, label: r.RoleName }));
        case 'POSITION': return lists.positions.map((p) => ({ value: p.PositionID, label: p.PositionName }));
        case 'UNIT':
        case 'UNIT_MANAGER': return lists.units.map((u) => ({ value: u.UnitID, label: u.UnitName }));
        default: return [];
    }
}

function DesignerCanvasInner({ graph, readOnly, onChange, users, roles, positions, units, conditionFields }: DesignerCanvasProps) {
    const [nodes, setNodes, onNodesChangeInternal] = useNodesState<Node<DesignerNodeData>>(
        graph.steps.map((s, i) => stepToNode(s, i))
    );
    const [edges, setEdges, onEdgesChangeInternal] = useEdgesState<Edge>(graph.transitions.map(transitionToEdge));
    const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);
    const [selectedEdgeId, setSelectedEdgeId] = useState<string | null>(null);
    const wrapperRef = useRef<HTMLDivElement>(null);
    const lookupLists = useMemo(() => ({ users, roles, positions, units }), [users, roles, positions, units]);

    // منبعِ حقیقت GraphDraft است؛ nodes/edges فقط بازتابِ بصریِ آن هستند.
    // هر تغییرِ معنادار (افزودن/حذف/جابه‌جایی/برچسب) بلافاصله به GraphDraftِ والد منتقل می‌شود.
    const syncGraph = useCallback(
        (nextNodes: Node<DesignerNodeData>[], nextEdges: Edge[]) => {
            const stepByCode = new Map(graph.steps.map((s) => [s.code, s]));
            const transitionByCode = new Map(graph.transitions.map((t) => [t.code, t]));

            const steps: StepDraft[] = nextNodes.map((n) => {
                const prev = stepByCode.get(n.id);
                const base = prev ?? newStepDraft(n.id, (n.data as DesignerNodeData).label, stepTypeFromNodeType(n.type), n.position);
                return {
                    ...base,
                    name: (n.data as DesignerNodeData).label,
                    description: (n.data as DesignerNodeData).description ?? null,
                    dueDurationHours: (n.data as DesignerNodeData).dueDurationHours ?? null,
                    positionX: Math.round(n.position.x),
                    positionY: Math.round(n.position.y),
                };
            });
            const transitions: TransitionDraft[] = nextEdges.map((e) => {
                const prev = transitionByCode.get(e.id) ?? newTransitionDraft(e.id, e.source, e.target);
                return {
                    ...prev,
                    code: e.id,
                    fromStepCode: e.source,
                    toStepCode: e.target,
                    label: (e.label as string) || null,
                };
            });
            const validCodes = new Set(steps.map((s) => s.code));
            onChange({
                steps,
                transitions,
                actions: graph.actions.filter((a) => validCodes.has(a.stepCode)),
                assignments: graph.assignments.filter((a) => validCodes.has(a.stepCode)),
            });
        },
        [graph, onChange]
    );

    const handleNodesChange = useCallback(
        (changes: NodeChange<Node<DesignerNodeData>>[]) => {
            if (readOnly) return;
            onNodesChangeInternal(changes);
            const hasStructural = changes.some((c) => c.type === 'remove' || (c.type === 'position' && c.dragging === false));
            if (hasStructural) {
                setNodes((curNodes) => {
                    setEdges((curEdges) => {
                        syncGraph(curNodes, curEdges);
                        return curEdges;
                    });
                    return curNodes;
                });
            }
        },
        [readOnly, onNodesChangeInternal, setNodes, setEdges, syncGraph]
    );

    const handleEdgesChange = useCallback(
        (changes: EdgeChange<Edge>[]) => {
            if (readOnly) return;
            onEdgesChangeInternal(changes);
            if (changes.some((c) => c.type === 'remove')) {
                setNodes((curNodes) => {
                    setEdges((curEdges) => {
                        syncGraph(curNodes, curEdges);
                        return curEdges;
                    });
                    return curNodes;
                });
            }
        },
        [readOnly, onEdgesChangeInternal, setNodes, setEdges, syncGraph]
    );

    const isValidConnection = useCallback(
        (conn: Connection | Edge) => {
            const source = 'source' in conn ? conn.source : null;
            const target = 'target' in conn ? conn.target : null;
            if (!source || !target) return false;
            if (source === target) return false;
            const sourceNode = nodes.find((n) => n.id === source);
            const targetNode = nodes.find((n) => n.id === target);
            if (sourceNode?.type === 'end') return false;
            if (targetNode?.type === 'start') return false;
            return true;
        },
        [nodes]
    );

    const onConnect = useCallback(
        (connection: Connection) => {
            if (readOnly || !isValidConnection(connection)) return;
            const transitionCodes = edges.map((e) => e.id);
            const code = nextTransitionCode(transitionCodes);
            const sourceNode = nodes.find((n) => n.id === connection.source);
            const isCondition = sourceNode?.type === 'condition';
            const branchLabel = isCondition ? (edges.filter((e) => e.source === connection.source).length === 0 ? 'بله' : 'خیر') : undefined;
            const newEdge: Edge = {
                id: code,
                source: connection.source!,
                target: connection.target!,
                label: branchLabel,
                markerEnd: { type: MarkerType.ArrowClosed, color: THEME.textLight },
                style: { stroke: THEME.textLight, strokeWidth: 1.6 },
                labelBgStyle: { fill: '#fff' },
                labelStyle: { fontSize: 11, fontWeight: 600 },
            };
            setEdges((cur) => {
                const next = addEdge(newEdge, cur);
                syncGraph(nodes, next);
                return next;
            });
        },
        [readOnly, isValidConnection, edges, nodes, setEdges, syncGraph]
    );

    const addNode = (type: PaletteType) => {
        if (readOnly) return;
        const existingCodes = nodes.map((n) => n.id);
        const code = nextCode(existingCodes, type);
        const meta = NODE_TYPE_META[type];
        const position = { x: 140 + ((nodes.length * 60) % 400), y: 100 + ((nodes.length * 70) % 320) };
        const label = type === 'start' ? 'شروع' : type === 'end' ? 'پایان' : `${meta.label} ${nodes.length + 1}`;
        const newNode: Node<DesignerNodeData> = { id: code, type, position, data: { label, description: null, dueDurationHours: null } };
        setNodes((cur) => {
            const next = [...cur, newNode];
            syncGraph(next, edges);
            return next;
        });
        setSelectedNodeId(code);
        setSelectedEdgeId(null);
    };

    const updateNodeData = (id: string, patch: Partial<DesignerNodeData>) => {
        setNodes((cur) => {
            const next = cur.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...patch } } : n));
            syncGraph(next, edges);
            return next;
        });
    };

    const updateEdgeLabel = (id: string, label: string) => {
        setEdges((cur) => {
            const next = cur.map((e) => (e.id === id ? { ...e, label: label || undefined } : e));
            syncGraph(nodes, next);
            return next;
        });
    };

    const updateTransitionField = (
        code: string,
        patch: Partial<Pick<TransitionDraft, 'conditionExpression' | 'priority' | 'isDefault' | 'ruleJson'>>
    ) => {
        onChange({
            ...graph,
            transitions: graph.transitions.map((t) => (t.code === code ? { ...t, ...patch } : t)),
        });
    };

    const deleteNode = (id: string) => {
        setNodes((cur) => {
            const nextNodes = cur.filter((n) => n.id !== id);
            setEdges((curEdges) => {
                const nextEdges = curEdges.filter((e) => e.source !== id && e.target !== id);
                syncGraph(nextNodes, nextEdges);
                return nextEdges;
            });
            return nextNodes;
        });
        setSelectedNodeId(null);
    };

    const deleteEdge = (id: string) => {
        setEdges((cur) => {
            const next = cur.filter((e) => e.id !== id);
            syncGraph(nodes, next);
            return next;
        });
        setSelectedEdgeId(null);
    };

    const selectedNode = selectedNodeId ? nodes.find((n) => n.id === selectedNodeId) : null;
    const selectedEdge = selectedEdgeId ? edges.find((e) => e.id === selectedEdgeId) : null;
    const outgoingOfSelected = selectedNode ? edges.filter((e) => e.source === selectedNode.id) : [];
    const hasStart = nodes.some((n) => n.type === 'start');

    const stepAssignments = selectedNode ? graph.assignments.filter((a) => a.stepCode === selectedNode.id) : [];
    const setStepAssignments = (next: AssignmentDraft[]) => {
        if (!selectedNode) return;
        const others = graph.assignments.filter((a) => a.stepCode !== selectedNode.id);
        onChange({ ...graph, assignments: [...others, ...next] });
    };
    const addAssignment = () => {
        if (readOnly || !selectedNode) return;
        setStepAssignments([...stepAssignments, newAssignmentDraft(selectedNode.id, stepAssignments.length)]);
    };
    const updateAssignmentAt = (idx: number, patch: Partial<AssignmentDraft>) => {
        setStepAssignments(stepAssignments.map((a, i) => (i === idx ? { ...a, ...patch } : a)));
    };
    const removeAssignmentAt = (idx: number) => {
        setStepAssignments(stepAssignments.filter((_, i) => i !== idx));
    };

    const selectedTransition = selectedEdge ? graph.transitions.find((t) => t.code === selectedEdge.id) : null;
    const fromStepTypeOfSelectedEdge = selectedEdge ? nodes.find((n) => n.id === selectedEdge.source)?.type : null;

    return (
        <div style={{ display: 'flex', gap: 12, height: 660, direction: 'rtl' }}>
            {/* ابزارها — سمتِ چپ */}
            {!readOnly && (
                <div style={{ width: 150, ...STYLES.card, padding: 12, order: 3 }}>
                    <Title level={5} style={{ marginTop: 0, fontSize: 13 }}>ابزارها</Title>
                    <Space direction="vertical" style={{ width: '100%' }} size={8}>
                        {(Object.keys(NODE_TYPE_META) as PaletteType[]).map((type) => {
                            const meta = NODE_TYPE_META[type];
                            const disabled = type === 'start' && hasStart;
                            return (
                                <Tooltip key={type} title={disabled ? 'فقط یک «شروع» مجاز است' : ''}>
                                    <Button
                                        icon={meta.icon}
                                        block
                                        disabled={disabled}
                                        style={{ textAlign: 'right', borderColor: meta.color, color: meta.color }}
                                        onClick={() => addNode(type)}
                                    >
                                        {meta.label}
                                    </Button>
                                </Tooltip>
                            );
                        })}
                    </Space>
                </div>
            )}

            {/* Canvas — وسط */}
            <div ref={wrapperRef} style={{ flex: 1, ...STYLES.card, padding: 0, overflow: 'hidden', order: 2 }}>
                <ReactFlow
                    nodes={nodes}
                    edges={edges}
                    nodeTypes={designerNodeTypes}
                    onNodesChange={handleNodesChange}
                    onEdgesChange={handleEdgesChange}
                    onConnect={onConnect}
                    isValidConnection={isValidConnection}
                    onNodeClick={(_, n) => { setSelectedNodeId(n.id); setSelectedEdgeId(null); }}
                    onEdgeClick={(_, e) => { setSelectedEdgeId(e.id); setSelectedNodeId(null); }}
                    onPaneClick={() => { setSelectedNodeId(null); setSelectedEdgeId(null); }}
                    nodesDraggable={!readOnly}
                    nodesConnectable={!readOnly}
                    elementsSelectable
                    deleteKeyCode={readOnly ? null : ['Delete', 'Backspace']}
                    fitView
                    proOptions={{ hideAttribution: true }}
                >
                    <Background />
                    <Controls position="bottom-left" />
                    <MiniMap position="bottom-right" pannable zoomable />
                </ReactFlow>
            </div>

            {/* Properties — سمتِ راست */}
            <div style={{ width: 320, ...STYLES.card, padding: 16, overflowY: 'auto', order: 1 }}>
                <Title level={5} style={{ marginTop: 0 }}>تنظیماتِ آیتم</Title>
                {selectedNode ? (
                    <Space direction="vertical" style={{ width: '100%' }} size={14}>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نامِ مرحله</Text>
                            <Input
                                value={(selectedNode.data as DesignerNodeData).label}
                                disabled={readOnly}
                                onChange={(e) => updateNodeData(selectedNode.id, { label: e.target.value })}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع</Text>
                            <Text strong>{NODE_TYPE_META[selectedNode.type as PaletteType]?.label ?? selectedNode.type}</Text>
                        </div>
                        {selectedNode.type !== 'start' && selectedNode.type !== 'end' && (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                                <Input.TextArea
                                    rows={3}
                                    value={(selectedNode.data as DesignerNodeData).description ?? ''}
                                    disabled={readOnly}
                                    placeholder="توضیحِ اختیاری..."
                                    onChange={(e) => updateNodeData(selectedNode.id, { description: e.target.value || null })}
                                />
                            </div>
                        )}

                        {selectedNode.type === 'task' && (
                            <>
                                <Divider style={{ margin: '4px 0' }} />
                                <div>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مهلتِ انجام (ساعت)</Text>
                                    <InputNumber
                                        min={1}
                                        style={{ width: '100%' }}
                                        placeholder="بدونِ محدودیت"
                                        disabled={readOnly}
                                        value={(selectedNode.data as DesignerNodeData).dueDurationHours ?? undefined}
                                        onChange={(v) => updateNodeData(selectedNode.id, { dueDurationHours: v ?? null })}
                                    />
                                </div>
                                <div>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>انجام‌دهندگان</Text>
                                    {stepAssignments.length === 0 ? (
                                        <Text type="secondary" style={{ fontSize: 12 }}>انجام‌دهنده‌ای تعریف نشده.</Text>
                                    ) : (
                                        <Space direction="vertical" style={{ width: '100%' }} size={8}>
                                            {stepAssignments.map((a, idx) => {
                                                const needsTarget = ASSIGNEE_TYPES.find((t) => t.value === a.assigneeType)?.needsTarget ?? false;
                                                return (
                                                    <div key={idx} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 8 }}>
                                                        <Space direction="vertical" style={{ width: '100%' }} size={6}>
                                                            <Space wrap size={6}>
                                                                <Select
                                                                    size="small"
                                                                    style={{ width: 128 }}
                                                                    value={a.assigneeType}
                                                                    disabled={readOnly}
                                                                    options={ASSIGNEE_TYPES}
                                                                    onChange={(v) => updateAssignmentAt(idx, { assigneeType: v, refId: null })}
                                                                />
                                                                <Select
                                                                    size="small"
                                                                    style={{ width: 84 }}
                                                                    value={a.isBackup ? 'BACKUP' : 'MAIN'}
                                                                    disabled={readOnly}
                                                                    options={[{ value: 'MAIN', label: 'اصلی' }, { value: 'BACKUP', label: 'جانشین' }]}
                                                                    onChange={(v) => updateAssignmentAt(idx, { isBackup: v === 'BACKUP' })}
                                                                />
                                                                {!readOnly && (
                                                                    <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => removeAssignmentAt(idx)} />
                                                                )}
                                                            </Space>
                                                            {needsTarget && (
                                                                <Select
                                                                    size="small"
                                                                    style={{ width: '100%' }}
                                                                    placeholder="انتخابِ هدف..."
                                                                    showSearch
                                                                    optionFilterProp="label"
                                                                    disabled={readOnly}
                                                                    value={a.refId ?? undefined}
                                                                    options={lookupOptions(a.assigneeType, lookupLists)}
                                                                    onChange={(v) => updateAssignmentAt(idx, { refId: v })}
                                                                />
                                                            )}
                                                        </Space>
                                                    </div>
                                                );
                                            })}
                                        </Space>
                                    )}
                                    {!readOnly && (
                                        <Button size="small" icon={<PlusOutlined />} style={{ marginTop: 8 }} onClick={addAssignment}>
                                            افزودنِ انجام‌دهنده
                                        </Button>
                                    )}
                                </div>
                                <Divider style={{ margin: '4px 0' }} />
                            </>
                        )}

                        {selectedNode.type === 'condition' && (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>خروجی‌ها (شاخه‌ها)</Text>
                                {outgoingOfSelected.length === 0 ? (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        برایِ افزودنِ خروجی، از نقطهٔ اتصالِ این آیتم به آیتمِ مقصد بکشید.
                                    </Text>
                                ) : (
                                    <Space direction="vertical" style={{ width: '100%' }} size={8}>
                                        {outgoingOfSelected.map((e) => {
                                            const targetNode = nodes.find((n) => n.id === e.target);
                                            return (
                                                <Space key={e.id} style={{ width: '100%' }}>
                                                    <Input
                                                        size="small"
                                                        style={{ width: 90 }}
                                                        value={(e.label as string) || ''}
                                                        disabled={readOnly}
                                                        placeholder="نامِ خروجی"
                                                        onChange={(ev) => updateEdgeLabel(e.id, ev.target.value)}
                                                    />
                                                    <Text style={{ fontSize: 12 }} type="secondary">
                                                        → به: {(targetNode?.data as DesignerNodeData)?.label ?? e.target}
                                                    </Text>
                                                    {!readOnly && (
                                                        <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => deleteEdge(e.id)} />
                                                    )}
                                                </Space>
                                            );
                                        })}
                                    </Space>
                                )}
                            </div>
                        )}
                        {!readOnly && selectedNode.type !== 'start' && (
                            <Popconfirm title="حذفِ این آیتم؟" description="اتصال‌هایِ متصل به آن هم حذف می‌شوند." onConfirm={() => deleteNode(selectedNode.id)} okText="بله" cancelText="خیر">
                                <Button danger icon={<DeleteOutlined />} block>حذفِ آیتم</Button>
                            </Popconfirm>
                        )}
                    </Space>
                ) : selectedEdge ? (
                    <Space direction="vertical" style={{ width: '100%' }} size={14}>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مبدأ</Text>
                            <Text>{(nodes.find((n) => n.id === selectedEdge.source)?.data as DesignerNodeData)?.label ?? selectedEdge.source}</Text>
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مقصد</Text>
                            <Text>{(nodes.find((n) => n.id === selectedEdge.target)?.data as DesignerNodeData)?.label ?? selectedEdge.target}</Text>
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>برچسبِ مسیر</Text>
                            <Input
                                value={(selectedEdge.label as string) || ''}
                                disabled={readOnly}
                                placeholder="مثلاً «بله»"
                                onChange={(e) => updateEdgeLabel(selectedEdge.id, e.target.value)}
                            />
                        </div>

                        <Divider style={{ margin: '4px 0' }} />

                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>اولویتِ بررسی (Priority)</Text>
                            <InputNumber
                                style={{ width: '100%' }}
                                disabled={readOnly}
                                value={selectedTransition?.priority ?? 100}
                                onChange={(v) => updateTransitionField(selectedEdge.id, { priority: v ?? 100 })}
                            />
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                عددِ کوچک‌تر زودتر بررسی می‌شود؛ در Stepِ CONDITION همین عدد ترتیبِ بررسیِ Ruleها را تعیین می‌کند.
                            </Text>
                        </div>

                        <div>
                            <Checkbox
                                disabled={readOnly}
                                checked={!!selectedTransition?.isDefault}
                                onChange={(e) => {
                                    const isDefault = e.target.checked;
                                    updateTransitionField(selectedEdge.id, {
                                        isDefault,
                                        // Default و Rule هم‌زمان مجاز نیستند (طبقِ Validationِ Backend) — با
                                        // فعال‌کردنِ Default، Ruleِ این گذار پاک می‌شود.
                                        ...(isDefault ? { ruleJson: null } : {}),
                                    });
                                }}
                            >
                                گذارِ پیش‌فرض (Default / Else) است
                            </Checkbox>
                        </div>

                        {fromStepTypeOfSelectedEdge === 'condition' && (
                            <>
                                <Divider style={{ margin: '4px 0' }} />
                                <div>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
                                        <Space size={6}>
                                            <span>Rule (شرط)</span>
                                            {selectedTransition?.ruleJson && <Tag color="processing" style={{ margin: 0 }}>دارایِ Rule</Tag>}
                                        </Space>
                                    </Text>
                                    {selectedTransition?.isDefault ? (
                                        <Text type="secondary" style={{ fontSize: 12 }}>
                                            گذارهایِ پیش‌فرض نمی‌توانند Rule داشته باشند — ابتدا تیکِ «گذارِ پیش‌فرض» را بردارید.
                                        </Text>
                                    ) : (
                                        <RuleBuilder
                                            fields={conditionFields}
                                            readOnly={readOnly}
                                            value={selectedTransition?.ruleJson ?? null}
                                            onChange={(rule: RuleGroup | null) => updateTransitionField(selectedEdge.id, { ruleJson: rule })}
                                        />
                                    )}
                                </div>
                            </>
                        )}

                        <Divider style={{ margin: '4px 0' }} />
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }} type="secondary">
                                شرطِ متنیِ قدیمی (Legacy — فقط ذخیره می‌شود، در اجرا مصرف نمی‌شود)
                            </Text>
                            <Input.TextArea
                                rows={2}
                                value={selectedTransition?.conditionExpression ?? ''}
                                disabled={readOnly}
                                placeholder="این فیلد منسوخ است؛ از Rule بالا استفاده کنید."
                                onChange={(e) => updateTransitionField(selectedEdge.id, { conditionExpression: e.target.value || null })}
                            />
                        </div>

                        {!readOnly && (
                            <Button danger icon={<DeleteOutlined />} block onClick={() => deleteEdge(selectedEdge.id)}>حذفِ اتصال</Button>
                        )}
                    </Space>
                ) : (
                    <Empty description="یک آیتم یا اتصال را انتخاب کنید" image={Empty.PRESENTED_IMAGE_SIMPLE} />
                )}
            </div>
        </div>
    );
}

function stepTypeFromNodeType(type: string | undefined): StepType {
    if (type === 'start') return 'START';
    if (type === 'end') return 'END';
    if (type === 'condition') return 'CONDITION';
    return 'USER_TASK';
}

export default function DesignerCanvas(props: DesignerCanvasProps) {
    return (
        <ReactFlowProvider>
            <DesignerCanvasInner {...props} />
        </ReactFlowProvider>
    );
}
