<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductWeakTitleRule implements SeoAuditRuleInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

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

        $criteria = new Criteria();
        $criteria->setLimit((int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit') ?? 100));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $result = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();
            $title = trim((string) ($translated['name'] ?? ''));

            if ($title !== '' && mb_strlen($title) < $minLength) {
                $result[] = [
                    'id' => $product->getId(),
                    'name' => $title,
                    'productNumber' => $product->getProductNumber(),
                    'stock' => $product->getStock(),
                ];
            }
        }

        return $result;
    }
}