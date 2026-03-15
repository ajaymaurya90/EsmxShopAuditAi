<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

class ProductWeakTitleRule extends AbstractProductSeoAuditRule
{

    public function getCode(): string
    {
        return 'product_weak_title';
    }

    public function getTitle(): string
    {
        return 'Products with weak title';
    }

    public function getSeverity(): string
    {
        return 'low';
    }

    public function getEntity(): string
    {
        return 'product';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkWeakProductTitle') ?? true);
    }

    public function audit(Context $context): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $minLength = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.minProductTitleLength') ?? 20);

        $result = [];

        /** @var ProductEntity $product */
        foreach ($this->loadProducts($context) as $product) {
            $translated = $product->getTranslated();
            $title = trim((string) ($translated['name'] ?? ''));

            if ($title !== '' && mb_strlen($title) < $minLength) {
                $result[] = $this->buildProductPayload($product, [
                    'name' => $title,
                ]);
            }
        }

        return $result;
    }

}