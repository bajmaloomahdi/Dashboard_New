export function newestFirst<T>(
    items: readonly T[],
    dateOf: (item: T) => string | null | undefined,
    idOf: (item: T) => number | string,
): T[] {
    return items.map((item) => {
        const timestamp = Date.parse((dateOf(item) ?? '').replace(' ', 'T'));
        return { item, timestamp: Number.isFinite(timestamp) ? timestamp : -Infinity };
    }).sort((a, b) => b.timestamp - a.timestamp
        || String(idOf(b.item)).localeCompare(String(idOf(a.item)), undefined, { numeric: true }))
        .map(({ item }) => item);
}
