<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoAuditDataProvider;
use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

abstract class AbstractScoredProductSeoAuditRule extends AbstractProductSeoAuditRule implements ProductSeoQualityRuleInterface
{
    public function __construct(
        SystemConfigService $systemConfigService,
        protected readonly ProductSeoAuditDataProvider $productSeoAuditDataProvider
    ) {
        parent::__construct($systemConfigService);
    }

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

    /**
     * @param array<string, bool> $seen
     */
    protected function shouldSkipInEffectiveMode(ProductEntity $product, string $fieldName, array &$seen): bool
    {
        if (!$this->productSeoAuditDataProvider->isEffectiveMode()) {
            return false;
        }

        $key = $this->productSeoAuditDataProvider->getEffectiveAuditKey($product, $fieldName);

        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function buildSeoIssuePayload(
        ProductEntity $product,
        string $fieldName,
        int $seoScore,
        array $payload = []
    ): array {
        $effectiveEntityId = $this->productSeoAuditDataProvider->getEffectiveProductId($product);

        return $this->buildProductPayload($product, array_merge($payload, [
            'id' => $effectiveEntityId,
            'fieldType' => $fieldName,
            'seoScore' => $seoScore,
            'effectiveEntityId' => $effectiveEntityId,
            'variantMode' => $this->productSeoAuditDataProvider->getVariantAuditMode(),
            'isInheritedEffectiveValue' => $this->productSeoAuditDataProvider->isInheritedEffectiveValue($product),
        ]));
    }
}