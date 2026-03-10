// src/Resources/app/administration/src/module/esmx-shop-audit-ai/page/esmx-shop-audit-dashboard/constants/grid-columns.constant.js

// Returns grid columns for general product issue tables
export function productGridColumns(tc) {
    return [
        {
            property: 'name',
            label: tc('esmx-shop-audit-ai.grid.productName'),
            routerLink: 'sw.product.detail',
            primary: true,
        },
        {
            property: 'productNumber',
            label: tc('esmx-shop-audit-ai.grid.productNumber'),
        },
        {
            property: 'stock',
            label: tc('esmx-shop-audit-ai.grid.stock'),
        },
    ];
}

// Returns grid columns for translation-related issue tables
export function translationGridColumns(tc) {
    return [
        {
            property: 'name',
            label: tc('esmx-shop-audit-ai.grid.productName'),
            routerLink: 'sw.product.detail',
            primary: true,
        },
        {
            property: 'productNumber',
            label: tc('esmx-shop-audit-ai.grid.productNumber'),
        },
        {
            property: 'missingLanguages',
            label: tc('esmx-shop-audit-ai.grid.missingLanguages'),
        },
    ];
}

// Returns grid columns for category issue tables
export function categoryGridColumns() {
    return [
        {
            property: 'name',
            label: 'Category',
            primary: true,
        },
        {
            property: 'id',
            label: 'ID',
        },
    ];
}