<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;

class ProductSeoAuditDataProvider
{
    private const int DEFAULT_PRODUCT_LIMIT = 100;
    private const string VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';
    private const string VARIANT_AUDIT_MODE_RAW = 'raw';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    // Loads products for SEO audit based on configured limit and variant audit mode.
    public function loadProducts(Context $context): ProductCollection
    {
        $limit = $this->getAuditProductLimit();
        $variantMode = $this->getVariantAuditMode();

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

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