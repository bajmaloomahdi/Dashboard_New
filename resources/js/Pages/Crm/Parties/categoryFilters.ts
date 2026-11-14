export const emptyCategory = '\u0000';

export type CategorySelections = { type: string[]; activity: string[]; nature: string[]; status: string[]; cooperation: string[] };

export const emptySelections = (): CategorySelections => ({ type: [], activity: [], nature: [], status: [], cooperation: [] });

export function filterPartyCategories<T>(
    rows: T[],
    selections: Partial<CategorySelections>,
    getters: Partial<Record<keyof CategorySelections, (row: T) => string[]>>,
): T[] {
    const active = (Object.keys(getters) as (keyof CategorySelections)[]).flatMap((key) => {
        const get = getters[key];
        return get && selections[key]?.length ? [{ get, selected: new Set(selections[key]) }] : [];
    });
    if (!active.length) return rows;

    return rows.filter((row) => active.every(({ selected, get }) => {
        const names = get(row).map((name) => name.trim()).filter(Boolean);
        return names.length ? names.some((name) => selected.has(name)) : selected.has(emptyCategory);
    }));
}
