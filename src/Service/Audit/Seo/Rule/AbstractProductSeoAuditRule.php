<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

abstract class AbstractProductSeoAuditRule implements ProductSeoAuditRuleInterface
{
    public function __construct(
        protected readonly SystemConfigService $systemConfigService
    ) {
    }

    public function audit(Context $context): array
    {
        return [];
    }

    abstract public function auditProducts(ProductCollection $products): array;

    protected function buildProductPayload(ProductEntity $product, array $extra = []): array
    {
        $translated = $product->getTranslated();
        $name = (string) ($translated['name'] ?? $product->getProductNumber() ?? $product->getId());

        return array_merge([
            'id' => $product->getId(),
            'parentId' => $product->getParentId(),
            'name' => $name,
            'productNumber' => $product->getProductNumber(),
            'stock' => $product->getStock(),
        ], $extra);
    }
}