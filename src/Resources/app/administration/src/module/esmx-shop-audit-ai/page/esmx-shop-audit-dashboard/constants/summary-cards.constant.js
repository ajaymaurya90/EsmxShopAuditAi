// Builds the summary cards shown at the top of the dashboard
export function buildSummaryCards(tc, totals = {}) {
    return [
        {
            key: 'missingDescription',
            label: tc('esmx-shop-audit-ai.dashboard.missingDescription'),
            count: totals.missingDescription || 0,
            target: 'audit-section-missing-description',
            severity: 'medium',
        },
        {
            key: 'missingCoverImage',
            label: tc('esmx-shop-audit-ai.dashboard.missingCoverImage'),
            count: totals.missingCoverImage || 0,
            target: 'audit-section-missing-cover-image',
            severity: 'low',
        },
        {
            key: 'inactiveProducts',
            label: tc('esmx-shop-audit-ai.dashboard.inactiveProducts'),
            count: totals.inactiveProducts || 0,
            target: 'audit-section-inactive-products',
            severity: 'high',
        },
        {
            key: 'outOfStockProducts',
            label: tc('esmx-shop-audit-ai.dashboard.outOfStockProducts'),
            count: totals.outOfStockProducts || 0,
            target: 'audit-section-out-of-stock-products',
            severity: 'critical',
        },
        {
            key: 'missingMetaTitle',
            label: tc('esmx-shop-audit-ai.dashboard.missingMetaTitle'),
            count: totals.missingMetaTitle || 0,
            target: 'audit-section-missing-meta-title',
            severity: 'low',
        },
        {
            key: 'missingCategory',
            label: tc('esmx-shop-audit-ai.dashboard.missingCategory'),
            count: totals.missingCategory || 0,
            target: 'audit-section-missing-category',
            severity: 'medium',
        },
        {
            key: 'missingManufacturer',
            label: tc('esmx-shop-audit-ai.dashboard.missingManufacturer'),
            count: totals.missingManufacturer || 0,
            target: 'audit-section-missing-manufacturer',
            severity: 'medium',
        },
        {
            key: 'missingPrice',
            label: tc('esmx-shop-audit-ai.dashboard.missingPrice'),
            count: totals.missingPrice || 0,
            target: 'audit-section-missing-price',
            severity: 'high',
        },
        {
            key: 'missingTranslation',
            label: tc('esmx-shop-audit-ai.dashboard.missingTranslation'),
            count: totals.missingTranslation || 0,
            target: 'audit-section-missing-translation',
            severity: 'medium',
        },

        // Newly snippet-based labels

        {
            key: 'product_missing_meta_description',
            label: tc('esmx-shop-audit-ai.dashboard.productMissingMetaDescription'),
            count: totals.product_missing_meta_description || 0,
            target: 'audit-section-product-missing-meta-description',
            severity: 'medium',
        },
        {
            key: 'product_weak_title',
            label: tc('esmx-shop-audit-ai.dashboard.productWeakTitle'),
            count: totals.product_weak_title || 0,
            target: 'audit-section-product-weak-title',
            severity: 'low',
        },
        {
            key: 'product_short_description',
            label: tc('esmx-shop-audit-ai.dashboard.productShortDescription'),
            count: totals.product_short_description || 0,
            target: 'audit-section-product-short-description',
            severity: 'medium',
        },
        {
            key: 'category_missing_meta_title',
            label: tc('esmx-shop-audit-ai.dashboard.categoryMissingMetaTitle'),
            count: totals.category_missing_meta_title || 0,
            target: 'audit-section-category-missing-meta-title',
            severity: 'medium',
        },
        {
            key: 'category_missing_meta_description',
            label: tc('esmx-shop-audit-ai.dashboard.categoryMissingMetaDescription'),
            count: totals.category_missing_meta_description || 0,
            target: 'audit-section-category-missing-meta-description',
            severity: 'medium',
        },
        {
            key: 'category_missing_description',
            label: tc('esmx-shop-audit-ai.dashboard.categoryMissingDescription'),
            count: totals.category_missing_description || 0,
            target: 'audit-section-category-missing-description',
            severity: 'low',
        },
    ];
}