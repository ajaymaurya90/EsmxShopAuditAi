<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

abstract class AbstractProductSeoAuditRule implements SeoAuditRuleInterface
{
    protected const DEFAULT_PRODUCT_LIMIT = 100;
    protected const VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';
    protected const VARIANT_AUDIT_MODE_RAW = 'raw';

    public function __construct(
        protected readonly EntityRepository $productRepository,
        protected readonly SystemConfigService $systemConfigService
    ) {
    }

    protected function loadProducts(Context $context): ProductCollection
    {
        $criteria = $this->buildCriteria();
        $searchContext = $this->buildProductAuditContext($context);

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $searchContext)->getEntities();

        return $products;
    }

    protected function buildCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->setLimit($this->getAuditProductLimit());
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        return $criteria;
    }

    protected function buildProductAuditContext(Context $context): Context
    {
        $auditContext = clone $context;
        $auditContext->setConsiderInheritance(
            $this->getVariantAuditMode() === self::VARIANT_AUDIT_MODE_EFFECTIVE
        );

        return $auditContext;
    }

    protected function getVariantAuditMode(): string
    {
        $mode = (string) ($this->systemConfigService->get('EsmxShopAuditAi.config.variantAuditMode')
            ?? self::VARIANT_AUDIT_MODE_EFFECTIVE);

        if (!\in_array($mode, [self::VARIANT_AUDIT_MODE_EFFECTIVE, self::VARIANT_AUDIT_MODE_RAW], true)) {
            return self::VARIANT_AUDIT_MODE_EFFECTIVE;
        }

        return $mode;
    }

    protected function getAuditProductLimit(): int
    {
        $limit = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit')
            ?? self::DEFAULT_PRODUCT_LIMIT);

        if ($limit <= 0) {
            return self::DEFAULT_PRODUCT_LIMIT;
        }

        return $limit;
    }

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