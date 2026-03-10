<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductShortDescriptionRule implements SeoAuditRuleInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

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

    public function audit(Context $context): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $minLength = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.minProductDescriptionLength') ?? 80);

        $criteria = new Criteria();
        $criteria->setLimit((int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit') ?? 100));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $result = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();
            $description = trim(strip_tags((string) ($translated['description'] ?? '')));

            if ($description !== '' && mb_strlen($description) < $minLength) {
                $result[] = [
                    'id' => $product->getId(),
                    'name' => (string) ($translated['name'] ?? $product->getProductNumber() ?? $product->getId()),
                    'productNumber' => $product->getProductNumber(),
                    'stock' => $product->getStock(),
                ];
            }
        }

        return $result;
    }
}