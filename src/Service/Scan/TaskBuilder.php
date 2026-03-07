<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

class TaskBuilder
{
    public function build(string $scanId, array $findings): array
    {
        $tasks = [];

        foreach ($findings as $finding) {
            $task = $this->buildTaskFromFinding($scanId, $finding);

            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    private function buildTaskFromFinding(string $scanId, array $finding): ?array
    {
        $code = $finding['code'] ?? '';
        $affectedCount = (int) ($finding['affectedCount'] ?? 0);

        if ($affectedCount <= 0) {
            return null;
        }

        $taskMap = [
            'missing_description' => [
                'code' => 'add_product_descriptions',
                'title' => sprintf('Add descriptions to %d products', $affectedCount),
                'priority' => 'medium',
            ],
            'missing_cover_image' => [
                'code' => 'upload_product_images',
                'title' => sprintf('Upload cover images to %d products', $affectedCount),
                'priority' => 'medium',
            ],
            'inactive_products' => [
                'code' => 'review_inactive_products',
                'title' => sprintf('Review %d inactive products', $affectedCount),
                'priority' => 'high',
            ],
            'out_of_stock_products' => [
                'code' => 'review_out_of_stock_products',
                'title' => sprintf('Review %d out-of-stock products', $affectedCount),
                'priority' => 'high',
            ],
            'missing_meta_title' => [
                'code' => 'add_meta_titles',
                'title' => sprintf('Add SEO meta titles to %d products', $affectedCount),
                'priority' => 'low',
            ],
            'missing_category' => [
                'code' => 'assign_product_categories',
                'title' => sprintf('Assign categories to %d products', $affectedCount),
                'priority' => 'medium',
            ],
            'missing_manufacturer' => [
                'code' => 'assign_product_manufacturers',
                'title' => sprintf('Assign manufacturers to %d products', $affectedCount),
                'priority' => 'medium',
            ],
            'missing_price' => [
                'code' => 'add_product_prices',
                'title' => sprintf('Add prices to %d products', $affectedCount),
                'priority' => 'high',
            ],
            'missing_translation' => [
                'code' => 'complete_product_translations',
                'title' => sprintf('Complete translations for %d products', $affectedCount),
                'priority' => 'medium',
            ],
        ];

        if (!isset($taskMap[$code])) {
            return null;
        }

        return [
            'scanId' => $scanId,
            'code' => $taskMap[$code]['code'],
            'title' => $taskMap[$code]['title'],
            'priority' => $taskMap[$code]['priority'],
            'affectedCount' => $affectedCount,
            'status' => 'open',
            'payloadJson' => [
                'findingCode' => $code,
                'findingTitle' => $finding['title'] ?? '',
            ],
        ];
    }
}