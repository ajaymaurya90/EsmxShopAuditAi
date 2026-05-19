<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ScanCapabilitiesResolver
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function resolve(): array
    {
        $enabled = $this->getBoolConfig('enableAudit', true);

        $groups = [
            'productHealth' => [
                'enabled' => $enabled && $this->getBoolConfig('productHealthAuditEnabled', true),
            ],
            'seo' => [
                'enabled' => $enabled && $this->getBoolConfig('seoAuditEnabled', true),
            ],
            'brokenLinks' => [
                'enabled' => $enabled && $this->getBoolConfig('brokenLinkAuditEnabled', true),
            ],
            'sales' => [
                'enabled' => $enabled && $this->getBoolConfig('salesAuditEnabled', true),
            ],
            'customerAudit' => [
                'enabled' => $enabled && $this->getBoolConfig('customerAuditEnabled', true),
            ],
        ];

        return [
            'enabled' => $enabled,
            'groups' => $groups,
        ];
    }

    public function applyToScanOptions(array $scanOptions, ?array $capabilities = null): array
    {
        $capabilities ??= $this->resolve();
        $groups = $capabilities['groups'] ?? [];

        foreach ($scanOptions as $groupKey => $groupOptions) {
            if ($groupKey === 'version' || !\is_array($groupOptions)) {
                continue;
            }

            $groupEnabled = (bool) ($groups[$groupKey]['enabled'] ?? true);

            if ($groupEnabled) {
                continue;
            }

            $scanOptions[$groupKey]['enabled'] = false;

            foreach ($scanOptions[$groupKey]['checks'] ?? [] as $checkKey => $enabled) {
                $scanOptions[$groupKey]['checks'][$checkKey] = false;
            }
        }

        return $scanOptions;
    }

    private function getBoolConfig(string $key, bool $default): bool
    {
        $value = $this->systemConfigService->get('EsmxShopAuditAi.config.' . $key);

        return $value === null ? $default : (bool) $value;
    }
}
