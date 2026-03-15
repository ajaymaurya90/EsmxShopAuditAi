export function getFindingImpact(tc, code) {
    const impactMap = {
        missing_description: tc('esmx-shop-audit-ai.dashboardImpact.missingDescription'),
        missing_cover_image: tc('esmx-shop-audit-ai.dashboardImpact.missingCoverImage'),
        inactive_products: tc('esmx-shop-audit-ai.dashboardImpact.inactiveProducts'),
        out_of_stock_products: tc('esmx-shop-audit-ai.dashboardImpact.outOfStockProducts'),
        missing_meta_title: tc('esmx-shop-audit-ai.dashboardImpact.missingMetaTitle'),
        missing_category: tc('esmx-shop-audit-ai.dashboardImpact.missingCategory'),
        missing_manufacturer: tc('esmx-shop-audit-ai.dashboardImpact.missingManufacturer'),
        missing_price: tc('esmx-shop-audit-ai.dashboardImpact.missingPrice'),
        missing_translation: tc('esmx-shop-audit-ai.dashboardImpact.missingTranslation'),
        product_missing_meta_description: tc('esmx-shop-audit-ai.dashboardImpact.productMissingMetaDescription'),
        product_weak_title: tc('esmx-shop-audit-ai.dashboardImpact.productWeakTitle'),
        product_short_description: tc('esmx-shop-audit-ai.dashboardImpact.productShortDescription'),
        category_missing_meta_title: tc('esmx-shop-audit-ai.dashboardImpact.categoryMissingMetaTitle'),
        category_missing_meta_description: tc('esmx-shop-audit-ai.dashboardImpact.categoryMissingMetaDescription'),
        category_missing_description: tc('esmx-shop-audit-ai.dashboardImpact.categoryMissingDescription'),
    };

    return impactMap[code] || tc('esmx-shop-audit-ai.findings.defaultImpact');
}