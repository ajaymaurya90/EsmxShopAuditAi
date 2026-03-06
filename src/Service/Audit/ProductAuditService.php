<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductAuditService
{
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function buildDashboardSummary(Context $context): array
    {
        $limit = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit') ?? self::DEFAULT_LIMIT);

        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $products = $this->loadProducts($context, $limit);

        $issues = [
            'missingDescription' => [],
            'missingCoverImage' => [],
            'inactiveProducts' => [],
            'outOfStockProducts' => [],
            'missingMetaTitle' => [],
        ];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();

            $description = trim((string) ($translated['description'] ?? ''));
            $metaTitle = trim((string) ($translated['metaTitle'] ?? ''));

            if ($description === '') {
                $issues['missingDescription'][] = $this->buildProductPayload($product);
            }

            if ($product->getCoverId() === null) {
                $issues['missingCoverImage'][] = $this->buildProductPayload($product);
            }

            if ($product->getActive() !== true) {
                $issues['inactiveProducts'][] = $this->buildProductPayload($product);
            }

            if (($product->getStock() ?? 0) <= 0) {
                $issues['outOfStockProducts'][] = $this->buildProductPayload($product);
            }

            if ($metaTitle === '') {
                $issues['missingMetaTitle'][] = $this->buildProductPayload($product);
            }
        }

        return [
            'meta' => [
                'scannedProducts' => $products->count(),
                'productLimit' => $limit,
            ],
            'totals' => [
                'missingDescription' => \count($issues['missingDescription']),
                'missingCoverImage' => \count($issues['missingCoverImage']),
                'inactiveProducts' => \count($issues['inactiveProducts']),
                'outOfStockProducts' => \count($issues['outOfStockProducts']),
                'missingMetaTitle' => \count($issues['missingMetaTitle']),
                'totalIssues' => array_sum([
                    \count($issues['missingDescription']),
                    \count($issues['missingCoverImage']),
                    \count($issues['inactiveProducts']),
                    \count($issues['outOfStockProducts']),
                    \count($issues['missingMetaTitle']),
                ]),
            ],
            'issues' => $issues,
        ];
    }

    private function loadProducts(Context $context, int $limit): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        return $products;
    }

    private function buildProductPayload(ProductEntity $product): array
    {
        $translated = $product->getTranslated();
        $name = (string) ($translated['name'] ?? $product->getProductNumber() ?? $product->getId());

        return [
            'id' => $product->getId(),
            'name' => $name,
            'productNumber' => $product->getProductNumber(),
            'active' => $product->getActive(),
            'stock' => $product->getStock(),
        ];
    }
}