import { toBool } from '../../Utils/bool';

export interface ProductCategoryRow {
    ProductCategoryID: number | string;
    ParentCategoryID: number | string | null;
    Code: string;
    DisplayName: string;
    SortOrder: number | string;
    IsActive: boolean | number | string;
    Path: string;
    Level: number | string;
    ChildCount: number | string;
    ActiveChildCount: number | string;
    ActiveUsageCount: number | string;
}

export interface CategoryNode extends ProductCategoryRow {
    key: number;
    children?: CategoryNode[];
}

const id = (v: number | string | null) => (v === null || v === undefined ? null : Number(v));

// همان قاعدهٔ sp_Crm_GetProductCategories: SortOrder > 0 ⇒ همان SortOrder؛ SortOrder = 0 ⇒ کد (عددی)؛ هم‌ارزها ⇒ کد، سپس شناسه
const sortKey = (r: ProductCategoryRow) => (Number(r.SortOrder) > 0 ? Number(r.SortOrder) : Number(r.Code));
const byOrder = (a: ProductCategoryRow, b: ProductCategoryRow) =>
    sortKey(a) - sortKey(b) || Number(a.Code) - Number(b.Code) || Number(a.ProductCategoryID) - Number(b.ProductCategoryID);

/** فهرستِ تختِ SP را به درخت تبدیل می‌کند؛ ردیف‌هایی که در `visible` نیستند حذف می‌شوند (برایِ جستجو/فیلتر). */
export function buildCategoryTree(rows: ProductCategoryRow[], visible?: Set<number>): CategoryNode[] {
    const byParent = new Map<number | null, ProductCategoryRow[]>();
    rows.forEach((r) => {
        if (visible && !visible.has(Number(r.ProductCategoryID))) return;
        const key = id(r.ParentCategoryID);
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key)!.push(r);
    });
    const build = (parent: number | null): CategoryNode[] =>
        (byParent.get(parent) || []).sort(byOrder).map((r) => {
            const children = build(Number(r.ProductCategoryID));
            return { ...r, key: Number(r.ProductCategoryID), children: children.length ? children : undefined };
        });
    return build(null);
}

/** شناسهٔ خودِ دسته و همهٔ زیرمجموعه‌هایش (برایِ حذف از گزینه‌هایِ والد و جلوگیری از حلقه در UI). */
export function selfAndDescendants(rows: ProductCategoryRow[], rootId: number): Set<number> {
    const result = new Set<number>([rootId]);
    let added = true;
    while (added) {
        added = false;
        rows.forEach((r) => {
            const parent = id(r.ParentCategoryID);
            if (parent !== null && result.has(parent) && !result.has(Number(r.ProductCategoryID))) {
                result.add(Number(r.ProductCategoryID));
                added = true;
            }
        });
    }
    return result;
}

/** شناسه‌هایِ ردیف‌هایِ منطبق + همهٔ اجدادشان — تا نتیجهٔ جستجو در جایِ درختیِ خودش دیده شود. */
export function withAncestors(rows: ProductCategoryRow[], matched: Iterable<number>): Set<number> {
    const parentOf = new Map(rows.map((r) => [Number(r.ProductCategoryID), id(r.ParentCategoryID)]));
    const result = new Set<number>();
    for (const m of matched) {
        let cur: number | null | undefined = m;
        while (cur !== null && cur !== undefined && !result.has(cur)) {
            result.add(cur);
            cur = parentOf.get(cur);
        }
    }
    return result;
}

export interface CategoryTreeSelectNode {
    value: number;
    title: string;
    disabled?: boolean;
    children?: CategoryTreeSelectNode[];
}

/**
 * داده برایِ TreeSelect. `exclude` کاملاً حذف می‌شود (خودِ دسته و زیرمجموعه‌هایش هنگامِ تغییرِ والد)؛
 * دستهٔ غیرفعال نمایش داده می‌شود ولی قابلِ‌انتخاب نیست، مگر `allowId` (مقدارِ فعلی).
 */
export function toTreeSelectData(rows: ProductCategoryRow[], exclude?: Set<number>, allowId?: number | null): CategoryTreeSelectNode[] {
    const convert = (nodes: CategoryNode[]): CategoryTreeSelectNode[] =>
        nodes
            .filter((n) => !exclude?.has(n.key))
            .map((n) => {
                const active = toBool(n.IsActive);
                const children = n.children ? convert(n.children) : [];
                return {
                    value: n.key,
                    title: active ? n.DisplayName : `${n.DisplayName} (غیرفعال)`,
                    disabled: !active && n.key !== allowId,
                    children: children.length ? children : undefined,
                };
            });
    return convert(buildCategoryTree(rows));
}
