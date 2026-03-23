<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

class FindingBuilder
{
    public function build(string $scanId, array $auditSummary): array
    {
        $issues = $auditSummary['issues'] ?? [];

        $map = [
            'missingCoverImage' => [
                'code' => 'missing_cover_image',
                'title' => 'Products without cover image',
                'severity' => 'low',
                'entity' => 'product',
            ],
            'inactiveProducts' => [
                'code' => 'inactive_products',
                'title' => 'Inactive products',
                'severity' => 'high',
                'entity' => 'product',
            ],
            'outOfStockProducts' => [
                'code' => 'out_of_stock_products',
                'title' => 'Out of stock products',
                'severity' => 'critical',
                'entity' => 'product',
            ],
            'missingCategory' => [
                'code' => 'missing_category',
                'title' => 'Products without category',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'missingManufacturer' => [
                'code' => 'missing_manufacturer',
                'title' => 'Products without manufacturer',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'missingPrice' => [
                'code' => 'missing_price',
                'title' => 'Products without price',
                'severity' => 'high',
                'entity' => 'product',
            ],
            'missingTranslation' => [
                'code' => 'missing_translation',
                'title' => 'Products with missing translations',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'product_name' => [
                'code' => 'product_name',
                'title' => 'Products with name issues',
                'severity' => 'low',
                'entity' => 'product',
            ],
            'product_description' => [
                'code' => 'product_description',
                'title' => 'Products with description issues',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'product_meta_title' => [
                'code' => 'product_meta_title',
                'title' => 'Products with meta title issues',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'product_meta_description' => [
                'code' => 'product_meta_description',
                'title' => 'Products with meta description issues',
                'severity' => 'medium',
                'entity' => 'product',
            ],
            'category_missing_meta_title' => [
                'code' => 'category_missing_meta_title',
                'title' => 'Categories without SEO meta title',
                'severity' => 'medium',
                'entity' => 'category',
            ],
            'category_missing_meta_description' => [
                'code' => 'category_missing_meta_description',
                'title' => 'Categories without SEO meta description',
                'severity' => 'medium',
                'entity' => 'category',
            ],
            'category_missing_description' => [
                'code' => 'category_missing_description',
                'title' => 'Categories without description',
                'severity' => 'low',
                'entity' => 'category',
            ],
        ];

        $findings = [];

        foreach ($map as $issueKey => $definition) {
            $items = $issues[$issueKey] ?? [];
            $affectedCount = \count($items);

            if ($affectedCount === 0) {
                continue;
            }

            $findings[] = [
                'scanId' => $scanId,
                'code' => $definition['code'],
                'title' => $definition['title'],
                'severity' => $definition['severity'],
                'entity' => $definition['entity'],
                'affectedCount' => $affectedCount,
                'payloadJson' => [
                    'issueKey' => $issueKey,
                    'items' => $items,
                ],
            ];
        }

        return $findings;
    }
}