<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;

class ProductShortDescriptionRule extends AbstractProductSeoAuditRule
{
    public function getCode(): string
    {
        return 'product_short_description';
    }

    public function getTitle(): string
    {
        return 'Products with short description';
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
        return (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkShortProductDescription') ?? true);
    }

    public function auditProducts(ProductCollection $products): array
    {
        $minLength = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.minProductDescriptionLength') ?? 80);
        $result = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();
            $description = trim(strip_tags((string) ($translated['description'] ?? '')));

            if ($description !== '' && mb_strlen($description) < $minLength) {
                $result[] = $this->buildProductPayload($product);
            }
        }

        return $result;
    }
}