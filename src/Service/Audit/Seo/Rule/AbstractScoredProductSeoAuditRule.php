<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;

abstract class AbstractScoredProductSeoAuditRule extends AbstractProductSeoAuditRule implements ProductSeoQualityRuleInterface
{
    /**
     * @param array<string, ProductSeoScoreResult> $scoreResults Indexed by product ID
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function auditProductsWithScores(ProductCollection $products, array $scoreResults): array;

    protected function getScoreResult(ProductEntity $product, array $scoreResults): ?ProductSeoScoreResult
    {
        $productId = $product->getId();

        if ($productId === null) {
            return null;
        }

        $scoreResult = $scoreResults[$productId] ?? null;

        return $scoreResult instanceof ProductSeoScoreResult ? $scoreResult : null;
    }
}