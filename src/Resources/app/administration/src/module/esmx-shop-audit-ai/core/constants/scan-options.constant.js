export const SCAN_OPTIONS_STORAGE_KEY = 'esmxShopAuditAi.scanOptions';

export const DEFAULT_SCAN_OPTIONS = {
    version: 1,
    productHealth: {
        enabled: true,
        checks: {
            missingCoverImage: true,
            inactiveProducts: true,
            outOfStockProducts: true,
            missingCategory: true,
            missingManufacturer: true,
            missingPrice: true,
            missingTranslation: true,
        },
    },
    seo: {
        enabled: true,
        checks: {
            product_name: true,
            product_description: true,
            product_meta_title: true,
            product_meta_description: true,
            category_missing_meta_title: true,
            category_missing_meta_description: true,
            category_missing_description: true,
        },
    },
    brokenLinks: {
        enabled: true,
        checks: {
            product_description: true,
            category_description: true,
            cms_content: true,
        },
    },
    sales: {
        enabled: true,
        checks: {
            salesKpis: true,
            topSellingProducts: true,
            lowStockBestSellers: true,
        },
    },
};

export const SCAN_OPTION_GROUP_ICONS = {
    productHealth: 'regular-products',
    seo: 'regular-search',
    brokenLinks: 'regular-link-horizontal-slash',
    sales: 'regular-chart-bar',
};

export const SCAN_OPTION_GROUP_TOOLTIP_KEYS = {
    productHealth: 'productHealth',
    seo: 'seo',
    brokenLinks: 'brokenLinks',
    sales: 'sales',
};

export const SCAN_OPTION_TOOLTIP_KEYS = {
    productHealth: {
        missingCoverImage: 'missingCoverImage',
        inactiveProducts: 'inactiveProducts',
        outOfStockProducts: 'outOfStockProducts',
        missingCategory: 'missingCategory',
        missingManufacturer: 'missingManufacturer',
        missingPrice: 'missingPrice',
        missingTranslation: 'missingTranslation',
    },
    seo: {
        product_name: 'product_name',
        product_description: 'product_description',
        product_meta_title: 'product_meta_title',
        product_meta_description: 'product_meta_description',
        category_missing_meta_title: 'category_missing_meta_title',
        category_missing_meta_description: 'category_missing_meta_description',
        category_missing_description: 'category_missing_description',
    },
    brokenLinks: {
        product_description: 'product_description_links',
        category_description: 'category_description_links',
        cms_content: 'cms_content',
    },
    sales: {
        salesKpis: 'salesKpis',
        topSellingProducts: 'topSellingProducts',
        lowStockBestSellers: 'lowStockBestSellers',
    },
};
