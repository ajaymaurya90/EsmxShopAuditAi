<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use EsmxShopAuditAi\Service\Audit\Seo\Rule\ProductSeoAuditRuleInterface;
use EsmxShopAuditAi\Service\Audit\Seo\Rule\SeoAuditRuleInterface;
use Shopware\Core\Framework\Context;

class SeoAuditService
{
    /**
     * @var SeoAuditRuleInterface[]
     */
    private readonly array $rules;

    public function __construct(
        private readonly ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        iterable $rules
    ) {
        $this->rules = is_array($rules) ? $rules : iterator_to_array($rules);
    }

    public function run(Context $context): array
    {
        $issues = [];
        $sharedProducts = null;

        foreach ($this->rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }

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
        }

        return $issues;
    }
}