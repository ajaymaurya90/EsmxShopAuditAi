<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;

interface ProductSeoAuditRuleInterface extends SeoAuditRuleInterface
{
    public function auditProducts(ProductCollection $products): array;
}