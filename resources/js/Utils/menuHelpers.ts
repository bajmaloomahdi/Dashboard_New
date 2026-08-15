import type { Menu, MenuTree } from '../Types';

/**
 * تبدیل لیست تخت منوها به ساختار درختی
 * @param menus - آرایه تخت منوها از دیتابیس
 * @returns آرایه درختی با children
 */
export function buildMenuTree(menus: Menu[]): MenuTree[] {
    // ساخت map برای دسترسی سریع
    const menuMap = new Map<number, MenuTree>();

    // اول همه منوها رو به map اضافه کن (با children خالی)
    menus.forEach((menu) => {
        menuMap.set(menu.MenuID, { ...menu, children: [] });
    });

    const tree: MenuTree[] = [];

    // حالا رابطه والد-فرزند رو بساز
    menus.forEach((menu) => {
        const node = menuMap.get(menu.MenuID)!;

        if (menu.ParentID === null) {
            // منوی ریشه (بدون والد)
            tree.push(node);
        } else {
            // زیرمنو - به children والدش اضافه کن
            const parent = menuMap.get(menu.ParentID);
            if (parent) {
                parent.children.push(node);
            }
        }
    });

    return tree;
}

/**
 * تبدیل درخت منو به فرمت مورد نیاز Ant Design Menu
 * @param tree - درخت منوها
 * @returns آرایه‌ای که Ant Design Menu می‌فهمه
 */
export function convertToAntMenu(tree: MenuTree[]): any[] {
    return tree.map((node) => {
        const item: any = {
            key: node.Url || `menu-${node.MenuID}`,
            label: node.MenuTitle,
            icon: node.Icon, // بعداً به آیکون Ant تبدیل می‌کنیم
        };

        if (node.children && node.children.length > 0) {
            item.children = convertToAntMenu(node.children);
        }

        return item;
    });
}

/**
 * پیدا کردن key منوی فعال بر اساس URL فعلی
 * @param tree - درخت منوها
 * @param currentUrl - URL فعلی
 * @returns کلید منوی فعال
 */
export function findActiveMenuKey(tree: MenuTree[], currentUrl: string): string {
    for (const node of tree) {
        if (node.Url === currentUrl) {
            return node.Url;
        }
        if (node.children && node.children.length > 0) {
            const found = findActiveMenuKey(node.children, currentUrl);
            if (found) return found;
        }
    }
    return '';
}