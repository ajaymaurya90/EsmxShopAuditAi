<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductSeoAuditDataProvider
{
    private const int DEFAULT_PRODUCT_LIMIT = 100;
    public const string VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';
    public const string VARIANT_AUDIT_MODE_RAW = 'raw';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function loadProducts(Context $context): ProductCollection
    {
        $limit = $this->getAuditProductLimit();
        $variantMode = $this->getVariantAuditMode();

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('categories');

        $searchContext = clone $context;
        $searchContext->setConsiderInheritance(
            $variantMode === self::VARIANT_AUDIT_MODE_EFFECTIVE
        );

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $searchContext)->getEntities();

        $this->logger->info('EsmxShopAuditAi SEO product data loaded', [
            'count' => $products->count(),
            'limit' => $limit,
            'variantMode' => $variantMode,
        ]);

        return $products;
    }

    public function getVariantAuditMode(): string
    {
        $mode = (string) ($this->systemConfigService->get('EsmxShopAuditAi.config.variantAuditMode')
            ?? self::VARIANT_AUDIT_MODE_EFFECTIVE);

        if (!\in_array($mode, [self::VARIANT_AUDIT_MODE_EFFECTIVE, self::VARIANT_AUDIT_MODE_RAW], true)) {
            return self::VARIANT_AUDIT_MODE_EFFECTIVE;
        }

        return $mode;
    }

    public function isEffectiveMode(): bool
    {
        return $this->getVariantAuditMode() === self::VARIANT_AUDIT_MODE_EFFECTIVE;
    }

    public function getEffectiveAuditKey(ProductEntity $product, string $fieldName): string
    {
        return $this->getEffectiveProductId($product) . ':' . $fieldName;
    }

    public function getEffectiveProductId(ProductEntity $product): string
    {
        if ($this->getVariantAuditMode() === self::VARIANT_AUDIT_MODE_RAW) {
            return (string) $product->getId();
        }

        return (string) ($product->getParentId() ?: $product->getId());
    }

    public function isInheritedEffectiveValue(ProductEntity $product): bool
    {
        if ($this->getVariantAuditMode() === self::VARIANT_AUDIT_MODE_RAW) {
            return false;
        }

        return $product->getParentId() !== null;
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
}