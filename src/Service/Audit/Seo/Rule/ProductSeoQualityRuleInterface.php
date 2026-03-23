<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use Shopware\Core\Content\Product\ProductCollection;

interface ProductSeoQualityRuleInterface extends ProductSeoAuditRuleInterface
{
    /**
     * @param array<string, ProductSeoScoreResult> $scoreResults Indexed by product ID
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditProductsWithScores(ProductCollection $products, array $scoreResults): array;
}