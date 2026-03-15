<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductSeoAuditDataProvider
{
    private const DEFAULT_PRODUCT_LIMIT = 100;
    private const VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';
    private const VARIANT_AUDIT_MODE_RAW = 'raw';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function loadProducts(Context $context): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->setLimit($this->getAuditProductLimit());
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $searchContext = clone $context;
        $searchContext->setConsiderInheritance(
            $this->getVariantAuditMode() === self::VARIANT_AUDIT_MODE_EFFECTIVE
        );

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $searchContext)->getEntities();

        return $products;
    }

    private function getAuditProductLimit(): int
    {
        $limit = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit')
            ?? self::DEFAULT_PRODUCT_LIMIT);

        if ($limit <= 0) {
            return self::DEFAULT_PRODUCT_LIMIT;
        }

        return $limit;
    }

    private function getVariantAuditMode(): string
    {
        $mode = (string) ($this->systemConfigService->get('EsmxShopAuditAi.config.variantAuditMode')
            ?? self::VARIANT_AUDIT_MODE_EFFECTIVE);

        if (!\in_array($mode, [self::VARIANT_AUDIT_MODE_EFFECTIVE, self::VARIANT_AUDIT_MODE_RAW], true)) {
            return self::VARIANT_AUDIT_MODE_EFFECTIVE;
        }

        return $mode;
    }
}