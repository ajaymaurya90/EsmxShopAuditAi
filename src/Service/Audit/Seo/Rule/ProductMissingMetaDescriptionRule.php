<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;

class ProductMissingMetaDescriptionRule extends AbstractProductSeoAuditRule
{
    public function getCode(): string
    {
        return 'product_missing_meta_description';
    }

    public function getTitle(): string
    {
        return 'Products without SEO meta description';
    }

    public function getSeverity(): string
    {
        return 'medium';
    }

    public function getEntity(): string
    {
        return 'product';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkProductMetaDescription') ?? true);
    }

    public function auditProducts(ProductCollection $products): array
    {
        $result = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

            if ($metaDescription === '') {
                $result[] = $this->buildProductPayload($product);
            }
        }

        return $result;
    }
}