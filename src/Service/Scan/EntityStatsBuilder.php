<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

class EntityStatsBuilder
{
    private const PRODUCT_ISSUE_KEYS = [
        'missingCoverImage',
        'inactiveProducts',
        'outOfStockProducts',
        'missingCategory',
        'missingManufacturer',
        'missingPrice',
        'missingTranslation',
        'product_name',
        'product_description',
        'product_meta_title',
        'product_meta_description',
    ];

    private const CATEGORY_ISSUE_KEYS = [
        'category_missing_meta_title',
        'category_missing_meta_description',
        'category_missing_description',
    ];

    /**
     * @param array<string, mixed> $auditSummary
     * @param array<string, int> $scannedCounts
     *
     * @return array<string, array{affected: int, scanned: int}>
     */
    public function build(array $auditSummary, array $scannedCounts): array
    {
        $affected = [
            'products' => [],
            'categories' => [],
            'cmsPages' => [],
        ];

        $issues = $auditSummary['issues'] ?? [];

        if (\is_array($issues)) {
            foreach ($issues as $issueKey => $items) {
                if (!\is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (!\is_array($item) || empty($item['id'])) {
                        continue;
                    }

                    $bucket = $this->resolveBucket((string) $issueKey, $item);

                    if ($bucket === null) {
                        continue;
                    }

                    $affected[$bucket][(string) $item['id']] = true;
                }
            }
        }

        return [
            'products' => [
                'affected' => \count($affected['products']),
                'scanned' => max(0, (int) ($scannedCounts['products'] ?? 0)),
            ],
            'categories' => [
                'affected' => \count($affected['categories']),
                'scanned' => max(0, (int) ($scannedCounts['categories'] ?? 0)),
            ],
            'cmsPages' => [
                'affected' => \count($affected['cmsPages']),
                'scanned' => max(0, (int) ($scannedCounts['cmsPages'] ?? 0)),
            ],
        ];
    }

    private function resolveBucket(string $issueKey, array $item): ?string
    {
        if ($issueKey === 'broken_links') {
            return match ((string) ($item['entity'] ?? '')) {
                'product' => 'products',
                'category' => 'categories',
                'cms_page' => 'cmsPages',
                default => null,
            };
        }

        if (\in_array($issueKey, self::PRODUCT_ISSUE_KEYS, true)) {
            return 'products';
        }

        if (\in_array($issueKey, self::CATEGORY_ISSUE_KEYS, true)) {
            return 'categories';
        }

        return null;
    }
}
