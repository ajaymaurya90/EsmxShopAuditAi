<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use EsmxShopAuditAi\Service\Audit\Seo\Rule\SeoAuditRuleInterface;
use Shopware\Core\Framework\Context;

class SeoAuditService
{
    /**
     * @var SeoAuditRuleInterface[]
     */
    private readonly array $rules;

    public function __construct(SeoAuditRuleInterface ...$rules)
    {
        $this->rules = $rules;
    }

    public function run(Context $context): array
    {
        $issues = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }

            $items = $rule->audit($context);

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