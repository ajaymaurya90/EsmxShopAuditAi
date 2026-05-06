<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

class ScanOptionsResolver
{
    private const DEFAULT_SCAN_OPTIONS = [
        'version' => 1,
        'productHealth' => [
            'enabled' => true,
            'checks' => [
                'missingCoverImage' => true,
                'inactiveProducts' => true,
                'outOfStockProducts' => true,
                'missingCategory' => true,
                'missingManufacturer' => true,
                'missingPrice' => true,
                'missingTranslation' => true,
            ],
        ],
        'seo' => [
            'enabled' => true,
            'checks' => [
                'product_name' => true,
                'product_description' => true,
                'product_meta_title' => true,
                'product_meta_description' => true,
                'category_missing_meta_title' => true,
                'category_missing_meta_description' => true,
                'category_missing_description' => true,
            ],
        ],
        'brokenLinks' => [
            'enabled' => true,
            'checks' => [
                'product_description' => true,
                'category_description' => true,
                'cms_content' => true,
            ],
        ],
        'sales' => [
            'enabled' => true,
            'checks' => [
                'salesKpis' => true,
                'topSellingProducts' => true,
                'lowStockBestSellers' => true,
            ],
        ],
    ];

    public function getDefaults(): array
    {
        return self::DEFAULT_SCAN_OPTIONS;
    }

    public function resolve(?array $scanOptions): array
    {
        $resolved = self::DEFAULT_SCAN_OPTIONS;

        if ($scanOptions === null || $scanOptions === []) {
            return $resolved;
        }

        foreach ($resolved as $groupKey => $groupDefaults) {
            if ($groupKey === 'version' || !\is_array($groupDefaults)) {
                continue;
            }

            $incomingGroup = $scanOptions[$groupKey] ?? null;

            if (!\is_array($incomingGroup)) {
                continue;
            }

            if (\array_key_exists('enabled', $incomingGroup)) {
                $resolved[$groupKey]['enabled'] = (bool) $incomingGroup['enabled'];
            }

            $incomingChecks = $incomingGroup['checks'] ?? null;

            if (!\is_array($incomingChecks)) {
                if ($resolved[$groupKey]['enabled'] === false) {
                    foreach ($groupDefaults['checks'] ?? [] as $checkKey => $defaultValue) {
                        $resolved[$groupKey]['checks'][$checkKey] = false;
                    }
                }

                continue;
            }

            foreach ($groupDefaults['checks'] ?? [] as $checkKey => $defaultValue) {
                if (\array_key_exists($checkKey, $incomingChecks)) {
                    $resolved[$groupKey]['checks'][$checkKey] = (bool) $incomingChecks[$checkKey];
                }
            }

            if (($incomingGroup['enabled'] ?? null) === false) {
                foreach ($groupDefaults['checks'] ?? [] as $checkKey => $defaultValue) {
                    $resolved[$groupKey]['checks'][$checkKey] = false;
                }
            }

            $resolved[$groupKey]['enabled'] = \in_array(true, $resolved[$groupKey]['checks'], true);
        }

        return $resolved;
    }
}
