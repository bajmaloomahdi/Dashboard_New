import ReportValueFilter from './ReportValueFilter';
import { showConfirmation } from '../../Components/Confirmation';
import './chart-builder.css';
import { BarChartOutlined, AreaChartOutlined, LineChartOutlined, PieChartOutlined, AppstoreOutlined, DashboardOutlined, DotChartOutlined, FundOutlined, FullscreenOutlined, PictureOutlined, SaveOutlined, FolderOpenOutlined } from '@ant-design/icons';
import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Card, Checkbox, Collapse, ConfigProvider, Input, InputNumber, List, Modal, Pagination, Select, Space, Spin, theme, message } from 'antd';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import * as echarts from 'echarts';
import MainLayout from '../../Layouts/MainLayout';

const types = { column:'ستونی', bar:'میله‌ای', line:'خطی', area:'ناحیه‌ای', pie:'دایره‌ای', donut:'دوناتی', treemap:'نقشه درختی', kpi:'کارت شاخص' };
const icons = { column:BarChartOutlined, bar:FundOutlined, line:LineChartOutlined, area:AreaChartOutlined, pie:PieChartOutlined, donut:DotChartOutlined, treemap:AppstoreOutlined, kpi:DashboardOutlined };
export type ChartLayout = {
    type: keyof typeof types; dimension?: string; series: { field:string; operation:string }[];
    limit:number; sort:string; title?:string; legend?:boolean; labels?:boolean; color?:string;
    parameterValues?:Record<string,string[]>;
    filters?: { field: string; values: string[] }[];
};
const emptyParameters: Record<string, string[]> = {};
const blank: ChartLayout = {type:'column', series:[], limit:20, sort:'dimension', legend:true};
export function ChartChoices({ value, change, fields, fieldLabels, measureFields }: {
    value:ChartLayout; change:(next:ChartLayout)=>void; fields:string[];
    fieldLabels:Record<string,string>; measureFields:string[];
}) {
    const options = (keys:string[]) => keys.map(key => ({value:key,label:fieldLabels[key]}));
    return <div className="chart-steps">
        <div className="chart-step"><label><b>۱</b> چه چیزی را می‌خواهید بررسی کنید؟</label>
        <Select aria-label="شاخص" showSearch={{ optionFilterProp: "label" }} size="large" placeholder="انتخاب شاخص" value={value.series[0]?.field} options={options(measureFields)} onChange={field=>change({...value,series:[{field,operation:'SUM'},...value.series.slice(1).filter(s=>s.field!==field)]})}/></div>
        <div className="chart-step"><label><b>۲</b> به چه تفکیکی؟</label>
        <Select aria-label="تفکیک" showSearch={{ optionFilterProp: "label" }} size="large" placeholder="انتخاب تفکیک" allowClear value={value.dimension} options={options(fields)} onChange={dimension=>change({...value,dimension,type:dimension==='YM' && value.type==='column' ? 'line' : value.type})}/></div>
        <div className="chart-step"><label><b>۳</b> نوع نمایش</label>
        <div className="chart-types" role="group" aria-label="نوع نمایش نمودار">
            {(Object.keys(types) as (keyof typeof types)[]).map(type=>{const Icon=icons[type]; return <Button key={type} htmlType="button" aria-pressed={value.type===type} onClick={()=>change({...value,type})}><Icon/><span>{types[type]}</span></Button>;})}
        </div></div>
    </div>;
}

type Param = {field:string; title:string; operator:string; required:boolean; default?:string[]; options?:{value:string;label:string}[]};
type Saved = {LayoutID:number; LayoutName:string; UpdatedAtUtc:string; IsDefault:boolean};
type Row = Record<string,string|number|null>;
const exact = new Intl.NumberFormat('fa-IR',{maximumFractionDigits:12});
const format = (n:unknown) => n == null ? '—' : exact.format(typeof n==='string' ? n.trim() : Number(n));
const compact = new Intl.NumberFormat('fa-IR',{notation:'compact',maximumFractionDigits:1});
const chartColors = {
    light: { blue:'#0849e8', page:'#f4f7fc', card:'#ffffff', border:'#dfe6f1', active:'#eaf2ff', text:'#101d38', muted:'#455774', shadow:'#173a7410' },
    dark: { blue:'#83afff', page:'#101827', card:'#172235', border:'#344561', active:'#233d68', text:'#edf4ff', muted:'#b7c8e3', shadow:'#00000035' },
    save:'#0849e8', saveHover:'#0640c8', white:'#ffffff',
    series:['#195bbb','#08a497','#419fea','#8744cc','#df5184','#ea852e','#dcae11','#8798b2'],
    highlights:['#559bff','#53dac7','#88ceff','#c193fc','#ffa1bf','#ffc28e','#ffe277','#c5d3e8'],
};
const palette = chartColors.series;
const number = (value:Row[string]) => value===null || value===undefined || String(value).trim()==='' || !Number.isFinite(Number(value)) ? null : Number(value);


export default function Chart({fields,fieldLabels,measureFields,reportCode,title,parameters}: {
    fields:string[]; fieldLabels:Record<string,string>; measureFields:string[]; reportCode:string; title:string; parameters:Param[];
}) {
    const url = '/reports/'+encodeURIComponent(reportCode);
    const {token} = theme.useToken();
    const dark = parseInt(token.colorBgContainer.replace('#','').slice(0,2),16) < 100;
    const colors = dark ? chartColors.dark : chartColors.light;
    const preview = useRef<HTMLDivElement>(null);
    const [api,context] = message.useMessage();
    const [layout,setLayout] = useState<ChartLayout>(blank);
    const [selected,setSelected] = useState<number>();
    const [defaults,setDefaults] = useState<ChartLayout|null>(null);
    const [saved,setSaved] = useState<Saved[]>([]);
    const [ready,setReady] = useState(false);
    const [paging,setPaging] = useState<{layout:ChartLayout; url:string; page:number}>();
    const page = paging?.layout === layout && paging.url === url ? paging.page : 1;
    const [total,setTotal] = useState(0);
    const [rows,setRows] = useState<Row[]>([]);
    const [loading,setLoading] = useState(false);
    const [error,setError] = useState('');
    const [picker,setPicker] = useState(false);
    const [editing,setEditing] = useState<{action:'save'|'rename'; id?:number}>();
    const [name,setName] = useState('');
    const surface = useRef<HTMLDivElement>(null);
    const instance = useRef<echarts.ECharts|null>(null);
    const read = async (id?:number, signal?:AbortSignal, apply=true) => {
        const {data} = await axios.get(url+'/layout',{params:{id},signal});
        setSaved(data.layouts); setDefaults(data.default);
        if(apply){setLayout(data.personal ?? data.default ?? blank); setSelected(data.selectedId ?? undefined);}
        if(data.invalid) api.warning('بعضی تنظیمات ذخیره‌شده با ستون‌های فعلی سازگار نیستند.');
    };
    useEffect(()=>{
        const controller=new AbortController();
        read(undefined,controller.signal).catch(()=>{if(!controller.signal.aborted) api.error('خواندن چیدمان انجام نشد.');})
            .finally(()=>{if(!controller.signal.aborted) setReady(true);});
        return ()=>controller.abort();
    },[url]);
    const parameterValues = layout.parameterValues ?? emptyParameters;
    const missing = parameters.some(p=>p.required && (parameterValues[p.field] ?? p.default ?? []).filter(Boolean).length < (p.operator==='between'?2:1));
    const eligible = layout.series.length>0 && (layout.type==='kpi'||!!layout.dimension) && !missing;
    useEffect(()=>{
        setRows([]); setError(''); setLoading(false);
        if(!ready||!eligible) { setTotal(0); return; }
        const controller=new AbortController();
        setLoading(true);
        const timer=setTimeout(async()=>{
            try {
                const {data}=await axios.post(url+'/execute',{chart:layout,parameterValues:layout.parameterValues,page},{signal:controller.signal});
                if(!controller.signal.aborted) {
                    setRows(data.rows);
                    setTotal(data.total);
                    const lastPage = Math.max(1, Math.ceil(data.total / layout.limit));
                    if(page > lastPage) setPaging({layout,url,page:lastPage});
                }
            } catch(e) {
                if(!controller.signal.aborted) setError(axios.isAxiosError(e) ? Object.values(e.response?.data?.errors ?? {}).flat().join('، ') || 'اجرای نمودار انجام نشد. تنظیمات را بررسی کنید.' : 'اجرای نمودار انجام نشد.');
            } finally {if(!controller.signal.aborted) setLoading(false);}
        },350);
        return ()=>{clearTimeout(timer); controller.abort();};
    },[layout,url,ready,eligible,page]);
    const names=layout.series.map(s=>fieldLabels[s.field]+' ('+s.operation+')');
    const radial=['pie','donut','treemap'].includes(layout.type);
    const shownTotal=radial ? rows.reduce((sum,r)=>sum+(number(r.metric_0)??0),0) : 0;
    const unsuitable=radial && rows.length>0 && (rows.some(r=>Number(r.metric_0)<0)||!rows.some(r=>Number(r.metric_0)>0));
    const chartTitle=layout.title || (layout.series.length ? fieldLabels[layout.series[0].field] + (layout.dimension && layout.type!=='kpi' ? ' به تفکیک '+fieldLabels[layout.dimension] : '') : title);
    useEffect(()=>{
        if(!surface.current || !rows.length || layout.type==='kpi' || unsuitable) return;
        const chart=echarts.init(surface.current,undefined,{renderer:'canvas'});
        instance.current=chart;
        const categories=rows.map(r=>String(r.category ?? 'بدون مقدار'));
        const data=rows.map((r,i)=>({name:categories[i],value:number(r.metric_0)}));
        const label={show:layout.labels ?? (rows.length<=10 && layout.series.length===1),color:colors.text,valueAnimation:false,formatter:(p:{value:unknown; percent?:number})=>p.percent==null?compact.format(Number(p.value)):exact.format(p.percent)+'٪'};
        const horizontal=layout.type==='bar';
        const axis={type:'value' as const,axisLabel:{formatter:(value:number)=>compact.format(value),color:colors.muted}};
        const groups={type:'category' as const,data:categories,axisLabel:{hideOverlap:true,width:112,overflow:'truncate' as const,color:colors.muted}};
        const series:echarts.SeriesOption[]=radial
            ? layout.type==='treemap' ? [{type:'treemap',data:data.filter(d=>d.value!==null),label:{show:true},name:names[0]}]
                : [{type:'pie',radius:layout.type==='donut'?['40%','68%']:'68%',data,label,name:names[0],tooltip:{valueFormatter:(_value,index)=>format(rows[index]?.metric_0)}}]
            : names.map((name, seriesIndex) => {
                const isBar = layout.type === 'bar' || layout.type === 'column';
                const metric = 'metric_' + seriesIndex;
                return {
                    name,
                    type: isBar ? 'bar' : 'line',
                    areaStyle: layout.type === 'area' ? {} : undefined,
                    label,
                    tooltip: { valueFormatter: (_value, index) => format(rows[index]?.[metric]) },
                    barMaxWidth: 58,
                    itemStyle: { borderRadius: horizontal ? [0, 5, 5, 0] : [5, 5, 0, 0] },
                    data: rows.map((row, index) => {
                        const value = number(row[metric]);
                        if (!isBar || value === null) return value;

                        const colorIndex = ((page - 1) * layout.limit + index + seriesIndex) % palette.length;
                        const customColor = colorIndex === 0 ? layout.color : undefined;
                        const color = new echarts.graphic.LinearGradient(
                            0, 0, horizontal ? 1 : 0, horizontal ? 0 : 1,
                            [
                                { offset: 0, color: customColor ?? chartColors.highlights[colorIndex] },
                                { offset: 1, color: customColor ?? palette[colorIndex] },
                            ],
                        );
                        return { value, itemStyle: { color } };
                    }),
                };
            });
        chart.setOption({
            color:layout.color?[layout.color,...palette.slice(1)]:palette,
            textStyle:{fontFamily:'Vazirmatn, sans-serif',color:colors.text},
            tooltip:{backgroundColor:colors.card,borderColor:colors.border,textStyle:{color:colors.text},trigger:radial?'item':'axis',confine:true,extraCssText:'direction:rtl;text-align:right;',valueFormatter:format},
            legend:{show:layout.legend!==false,bottom:0,type:'scroll',textStyle:{color:colors.text}},
            grid:radial?undefined:{top:32,bottom:52,left:58,right:30,containLabel:true},
            xAxis:radial?undefined:horizontal?axis:groups,yAxis:radial?undefined:horizontal?groups:axis,series,
        });
        chart.resize();
        const observer=new ResizeObserver(()=>chart.resize());
        observer.observe(surface.current);
        return ()=>{observer.disconnect();chart.dispose();instance.current=null;};
    },[rows,layout,unsuitable,colors,page]);
    async function mutate(action:string,id?:number,layoutName?:string){
        try {
            const {data}=await axios.put(url+'/layout',{action,id,name:layoutName,layout:action==='save'?layout:undefined});
            if(action==='save') setSelected(data.id);
            if(action==='delete'&&id===selected) setSelected(undefined);
            setEditing(undefined);
            await read(undefined,undefined,false);
            api.success('تغییرات ذخیره شد.');
        } catch(e){api.error(axios.isAxiosError(e)? e.response?.data?.errors?.name?.[0] ?? 'ذخیره تنظیمات انجام نشد.' : 'ذخیره تنظیمات انجام نشد.');}
    }
    return <MainLayout><Head title={title}/><ConfigProvider direction="rtl">{context}
        <div dir="rtl" className="chart-builder" style={{'--chart-border':colors.border,'--chart-bg':colors.card,'--chart-fill':colors.page,'--chart-blue':colors.blue,'--chart-active':colors.active,'--chart-text':colors.text,'--chart-shadow':colors.shadow,'--chart-save':chartColors.save,'--chart-save-hover':chartColors.saveHover,'--chart-white':chartColors.white} as React.CSSProperties}>
            <div className="chart-toolbar"><div><h1><BarChartOutlined/> نمودارساز گزارش‌ها</h1><div className="chart-subtitle">{title} · طراحی و پیش‌نمایش نمودار</div></div>
            <Space wrap>
                <Button icon={<FolderOpenOutlined/>} disabled={!ready} onClick={()=>setPicker(true)}>باز کردن چیدمان</Button>
                <Button icon={<SaveOutlined/>} type="primary" disabled={!ready||!eligible} onClick={()=>selected?void mutate('save',selected):(setName(''),setEditing({action:'save'}))}>ذخیره</Button>
                <Button disabled={!ready||!eligible} onClick={()=>{setName('');setEditing({action:'save'});}}>ذخیره به‌عنوان جدید</Button>
                <Button onClick={()=>{setSelected(undefined);setLayout(defaults??blank);}}>بازگشت به پیش‌فرض</Button>
            </Space></div>
            <Card className="chart-panel chart-wizard"><ChartChoices value={layout} change={setLayout} {...{fields,fieldLabels,measureFields}}/></Card>
            <div className="chart-workspace">
            <Card className="chart-panel" title={<Space><BarChartOutlined/> طراحی نمودار</Space>}>

                <Collapse style={{marginTop:16}} items={[{key:'advanced',label:'تنظیمات پیشرفته',children:<Space wrap>
                    <Select mode="multiple" style={{minWidth:220}} showSearch={{ optionFilterProp: "label" }} value={layout.series.map(s=>s.field)} options={fields.map(field=>({value:field,label:fieldLabels[field]}))}
                        onChange={(keys:string[])=>setLayout({...layout,series:keys.slice(0,4).map(field=>layout.series.find(s=>s.field===field)??{field,operation:measureFields.includes(field)?'SUM':'COUNT'})})}/>
                    {layout.series.map((s,i)=><Select key={s.field} aria-label={'تجمیع '+fieldLabels[s.field]} value={s.operation}
                        options={(measureFields.includes(s.field)?['SUM','COUNT','AVG','MIN','MAX']:['COUNT']).map(op=>({label:fieldLabels[s.field]+' · '+op,value:op}))}
                        onChange={operation=>setLayout({...layout,series:layout.series.map((item,j)=>i===j?{...item,operation}:item)})}/>)}
                    <InputNumber aria-label="تعداد گروه‌ها در هر صفحه" min={1} max={100} value={layout.limit} onChange={limit=>limit&&setLayout({...layout,limit})}/>
                    <Select value={layout.sort} options={[{value:'dimension',label:'ترتیب گروه'},{value:'desc',label:'بیشترین مقدار'},{value:'asc',label:'کمترین مقدار'}]} onChange={sort=>setLayout({...layout,sort})}/>
                    <Input placeholder="عنوان نمودار" maxLength={120} value={layout.title} onChange={e=>setLayout({...layout,title:e.target.value})}/>
                    <Input aria-label="رنگ نمودار" type="color" value={layout.color??palette[0]} onChange={e=>setLayout({...layout,color:e.target.value})}/>
                    <Checkbox checked={layout.legend!==false} onChange={e=>setLayout({...layout,legend:e.target.checked})}>راهنمای سری‌ها</Checkbox>
                    <Checkbox checked={layout.labels} onChange={e=>setLayout({...layout,labels:e.target.checked})}>برچسب داده</Checkbox>
                </Space>}]} />
                <Card size="small" title="فیلترهای نمودار" style={{ marginTop: 16 }}>
                    <Space direction="vertical" style={{ width: '100%' }}>
                        <Select
                            aria-label="فیلدهای فیلتر نمودار"
                            mode="multiple"
                            allowClear
                            maxCount={20}
                            style={{ width: '100%' }}
                            placeholder="انتخاب فیلد برای فیلتر"
                            showSearch={{ optionFilterProp: 'label' }}
                            value={(layout.filters ?? []).map(filter => filter.field)}
                            options={fields.map(field => ({ value: field, label: fieldLabels[field] }))}
                            onChange={(selected: string[]) => setLayout(current => ({
                                ...current,
                                filters: selected.map(field => current.filters?.find(filter => filter.field === field) ?? { field, values: [] }),
                            }))}
                        />
                        {missing && (layout.filters?.length ?? 0) > 0 &&
                            <Alert type="info" message="برای دریافت گزینه‌های فیلتر، پارامترهای اجباری را تکمیل کنید." />}
                        {!missing && layout.filters?.map(({ field, values }) => (
                            <div key={field}>
                                <div>{fieldLabels[field]}</div>
                                <ReportValueFilter field={field} caption={fieldLabels[field]} value={values}
                                    reportUrl={url} parameterValues={parameterValues}
                                    onChange={next => setLayout(current => ({
                                        ...current,
                                        filters: current.filters?.map(filter => filter.field === field ? { field, values: next } : filter),
                                    }))}
                                />
                            </div>
                        ))}
                    </Space>
                </Card>
                <Space wrap style={{marginTop:16}}>{parameters.map(p=>{
                    const values=parameterValues[p.field]??p.default??[];
                    const update=(values:string[])=>setLayout({...layout,parameterValues:{...parameterValues,[p.field]:values}});
                    return <Space key={p.field} direction="vertical"><label>{p.title}{p.required?' *':''}</label>
                        {p.options ? <Select aria-label={p.title} placeholder="جست‌وجو و انتخاب" showSearch={{optionFilterProp:'label'}} style={{minWidth:180}} allowClear value={values}
                            mode="multiple" maxCount={20} options={p.options} onChange={update}/>
                            : p.operator==='eq' || p.operator==='in' ? <ReportValueFilter reportUrl={url} field={p.field} caption={p.title} value={values} onChange={update} parameterLookup/>
                            : Array.from({length:p.operator==='between'?2:1},(_,i)=><Input key={i} value={values[i]??''} onChange={e=>{const next=[...values];next[i]=e.target.value;update(Array.from(next,v=>v??''));}}/>)}
                    </Space>;
                })}</Space>
            </Card>
            <div className="chart-preview" ref={preview}><Card className="chart-panel" title={<Space><AreaChartOutlined/> پیش‌نمایش نمودار</Space>} extra={<Space><Button aria-label="تمام‌صفحه" icon={<FullscreenOutlined/>} onClick={()=>void (document.fullscreenElement ? document.exitFullscreen() : preview.current?.requestFullscreen())?.catch(()=>api.warning('تمام‌صفحه در دسترس نیست.'))}/><Button icon={<PictureOutlined/>} disabled={!rows.length||layout.type==='kpi'||unsuitable} onClick={()=>{
                const link=document.createElement('a'); const png=instance.current?.getDataURL({type:'png',pixelRatio:2,backgroundColor:colors.card});
                if(png){link.href=png;link.download='chart.png';link.click();}
            }}>خروجی PNG</Button></Space>}>
                <h2 className="chart-heading">{chartTitle}</h2>
                {radial && shownTotal>0 && <div className="chart-total">جمع گروه‌های نمایش‌داده‌شده: {compact.format(shownTotal)}</div>}
                {error && <Alert type="error" message={error}/>}
                {unsuitable && <Alert type="warning" message="این نمایش به مقادیر غیرمنفی و دست‌کم یک مقدار مثبت نیاز دارد."/>}
                <Spin spinning={loading||!ready}>
                    {!rows.length&&!error&&!loading&&<Alert type="info" message={missing?'پارامترهای اجباری را تکمیل کنید.':eligible?'داده‌ای یافت نشد.':'شاخص و فیلد گروه‌بندی را انتخاب کنید.'}/>}
                    {rows.length>0&&layout.type==='kpi'&&<Space wrap>{names.map((name,i)=><Card key={i} title={name}><strong style={{fontSize:26}}>{format(rows[0]['metric_'+i])}</strong></Card>)}</Space>}
                    {rows.length>0&&layout.type!=='kpi'&&!unsuitable&&<div className={radial?'chart-radial':'chart-plot'}><div ref={surface} className="chart-surface"/>{radial && <div className="chart-breakdown"><table><thead><tr><th>{fieldLabels[layout.dimension??'']}</th><th>{fieldLabels[layout.series[0].field]}</th><th>سهم از نمایش</th></tr></thead><tbody>{rows.map((row,i)=><tr key={i}><td><i style={{background:(i===0?layout.color:undefined)??palette[i%palette.length]}}/>{row.category??'بدون مقدار'}</td><td>{format(row.metric_0)}</td><td>{exact.format(Math.round(Number(row.metric_0??0)/shownTotal*1000)/10)}٪</td></tr>)}</tbody></table></div>}</div>}
                </Spin>
                {eligible && !error && layout.type !== 'kpi' && total > layout.limit &&
                    <Pagination current={page} total={total} pageSize={layout.limit} showSizeChanger={false}
                        disabled={loading} onChange={nextPage=>setPaging({layout,url,page:nextPage})}/>}
            </Card></div>
            </div>
        </div>
        <Modal title="نمودارهای ذخیره‌شده" open={picker} footer={null} onCancel={()=>setPicker(false)}>
            <List style={{maxHeight:460,overflowY:'auto'}} dataSource={saved} locale={{emptyText:'نموداری ذخیره نشده است.'}} renderItem={item=><List.Item>
                <Space wrap><Button type="link" onClick={()=>void read(item.LayoutID).then(()=>setPicker(false)).catch(()=>api.error('باز کردن نمودار انجام نشد.'))}>{item.LayoutName}</Button>
                    <small>{item.UpdatedAtUtc}{item.IsDefault?' · پیش‌فرض':''}</small>
                    <Button size="small" onClick={()=>{setName(item.LayoutName);setEditing({action:'rename',id:item.LayoutID});}}>تغییر نام</Button>
                    <Button size="small" disabled={!!item.IsDefault} onClick={()=>void mutate('default',item.LayoutID)}>پیش‌فرض</Button>
                    <Button size="small" danger onClick={()=>showConfirmation({title:'نمودار حذف شود؟',onOk:()=>mutate('delete',item.LayoutID)})}>حذف</Button>
                </Space></List.Item>}/>
        </Modal>
        <Modal title={editing?.action==='rename'?'تغییر نام نمودار':'نام نمودار'} open={!!editing} onCancel={()=>setEditing(undefined)}
            okButtonProps={{disabled:!name.trim()}} onOk={()=>editing&&mutate(editing.action,editing.id,name.trim())}>
            <Input value={name} maxLength={120} onChange={e=>setName(e.target.value)}/>
        </Modal>
    </ConfigProvider></MainLayout>;
}
