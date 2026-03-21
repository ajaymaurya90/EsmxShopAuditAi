<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use EsmxShopAuditAi\Service\Audit\Seo\Rule\ProductSeoAuditRuleInterface;
use EsmxShopAuditAi\Service\Audit\Seo\Rule\SeoAuditRuleInterface;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;

class SeoAuditService
{
    /**
     * @var SeoAuditRuleInterface[]
     */
    private readonly array $rules;

    public function __construct(
        private readonly ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly LoggerInterface $logger,
        iterable $rules
    ) {
        $this->rules = is_array($rules) ? $rules : iterator_to_array($rules);
    }

    // Runs all enabled SEO audit rules and reuses a shared product collection for product-based rules.
    public function run(Context $context): array
    {
        $issues = [];
        $sharedProducts = null;
        $executedRules = 0;
        $issueGroups = 0;
        $affectedItems = 0;

        foreach ($this->rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }

            $executedRules++;

            if ($rule instanceof ProductSeoAuditRuleInterface) {
                $sharedProducts ??= $this->productSeoAuditDataProvider->loadProducts($context);
                $items = $rule->auditProducts($sharedProducts);
            } else {
                $items = $rule->audit($context);
            }

            if ($items === []) {
                continue;
            }

            $issues[$rule->getCode()] = [
                'title' => $rule->getTitle(),
                'severity' => $rule->getSeverity(),
                'entity' => $rule->getEntity(),
                'items' => $items,
            ];

            $issueGroups++;
            $affectedItems += \count($items);
        }

        $this->logger->info('EsmxShopAuditAi SEO audit completed', [
            'executedRules' => $executedRules,
            'issueGroups' => $issueGroups,
            'affectedItems' => $affectedItems,
        ]);

        return $issues;
    }
}