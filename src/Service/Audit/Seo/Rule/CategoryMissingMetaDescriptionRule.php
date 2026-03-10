<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CategoryMissingMetaDescriptionRule implements SeoAuditRuleInterface
{
    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getCode(): string
    {
        return 'category_missing_meta_description';
    }

    public function getTitle(): string
    {
        return 'Categories without SEO meta description';
    }

    public function getSeverity(): string
    {
        return 'medium';
    }

    public function getEntity(): string
    {
        return 'category';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkCategorySeo') ?? true);
    }

    public function audit(Context $context): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->setLimit(100);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var CategoryCollection $categories */
        $categories = $this->categoryRepository->search($criteria, $context)->getEntities();

        $result = [];

        /** @var CategoryEntity $category */
        foreach ($categories as $category) {
            $translated = $category->getTranslated();
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

            if ($metaDescription === '') {
                $result[] = [
                    'id' => $category->getId(),
                    'name' => (string) ($translated['name'] ?? $category->getId()),
                ];
            }
        }

        return $result;
    }
}