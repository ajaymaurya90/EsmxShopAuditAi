<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use EsmxShopAuditAi\Service\Audit\Seo\Rule\ProductSeoAuditRuleInterface;
use EsmxShopAuditAi\Service\Audit\Seo\Rule\ProductSeoQualityRuleInterface;
use EsmxShopAuditAi\Service\Audit\Seo\Rule\SeoAuditRuleInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

class SeoAuditService
{
    /**
     * @var SeoAuditRuleInterface[]
     */
    private readonly array $rules;

    public function __construct(
        private readonly ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly SeoScoringService $seoScoringService,
        private readonly LoggerInterface $logger,
        iterable $rules
    ) {
        $this->rules = is_array($rules) ? $rules : iterator_to_array($rules);
    }

    // Runs all enabled SEO audit rules and reuses a shared product collection for product-based rules.
    public function run(Context $context): SeoAuditResult
    {
        $issues = [];
        $sharedProducts = null;
        $scoreResults = null;
        $executedRules = 0;

        foreach ($this->rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }

            $executedRules++;

            if ($rule instanceof ProductSeoAuditRuleInterface) {
                $sharedProducts ??= $this->productSeoAuditDataProvider->loadProducts($context);

                if ($rule instanceof ProductSeoQualityRuleInterface) {
                    $scoreResults ??= $this->seoScoringService->scoreProducts($sharedProducts);
                    $items = $rule->auditProductsWithScores($sharedProducts, $scoreResults);
                } else {
                    $items = $rule->auditProducts($sharedProducts);
                }
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

        if ($sharedProducts !== null) {
            $scoreResults ??= $this->seoScoringService->scoreProducts($sharedProducts);
        } else {
            $scoreResults = [];
        }

        $result = new SeoAuditResult(
            issues: $issues,
            kpi: $this->seoScoringService->buildKpiResult($scoreResults)
        );

        $this->logger->info('EsmxShopAuditAi SEO audit completed', [
            'executedRules' => $executedRules,
            'issueGroups' => $result->getIssueGroupCount(),
            'affectedItems' => $result->getAffectedItemCount(),
            'totalProducts' => $result->getKpi()->getTotalProducts(),
            'productsNeedingImprovement' => $result->getKpi()->getProductsNeedingImprovement(),
            'averageOverallScore' => $result->getKpi()->getAverageOverallScore(),
        ]);

        return $result;
    }
}