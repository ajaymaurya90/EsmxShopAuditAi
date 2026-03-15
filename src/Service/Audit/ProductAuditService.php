<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductAuditService
{
    private const DEFAULT_LIMIT = 100;
    private const VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';   // audit should evaluate the effective storefront data inherited from parent products
    private const VARIANT_AUDIT_MODE_RAW = 'raw';               // strictly check the raw values stored on variant records

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $languageRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function buildDashboardSummary(Context $context): array
    {
        $limit = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit') ?? self::DEFAULT_LIMIT);

        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $variantAuditMode = $this->getVariantAuditMode();

        $enableAudit = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.enableAudit') ?? true);
        $checkMissingManufacturer = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkMissingManufacturer') ?? true);
        $checkMissingTranslations = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkMissingTranslations') ?? true);
        $checkSeoFields = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkSeoFields') ?? true);

        if ($enableAudit !== true) {
            return [
                'meta' => [
                    'scannedProducts' => 0,
                    'productLimit' => $limit,
                    'variantAuditMode' => $variantAuditMode,
                ],
                'totals' => [
                    'missingDescription' => 0,
                    'missingCoverImage' => 0,
                    'inactiveProducts' => 0,
                    'outOfStockProducts' => 0,
                    'missingMetaTitle' => 0,
                    'missingCategory' => 0,
                    'missingManufacturer' => 0,
                    'missingPrice' => 0,
                    'missingTranslation' => 0,
                    'totalIssues' => 0,
                ],
                'issues' => [
                    'missingDescription' => [],
                    'missingCoverImage' => [],
                    'inactiveProducts' => [],
                    'outOfStockProducts' => [],
                    'missingMetaTitle' => [],
                    'missingCategory' => [],
                    'missingManufacturer' => [],
                    'missingPrice' => [],
                    'missingTranslation' => [],
                ],
            ];
        }

        $products = $this->loadProducts($context, $limit, $variantAuditMode);

        $languages = $this->loadLanguages($context);

        $issues = [
            'missingDescription' => [],
            'missingCoverImage' => [],
            'inactiveProducts' => [],
            'outOfStockProducts' => [],
            'missingMetaTitle' => [],
            'missingCategory' => [],
            'missingManufacturer' => [],
            'missingPrice' => [],
            'missingTranslation' => [],
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

            if ($checkSeoFields && $metaTitle === '') {
                $issues['missingMetaTitle'][] = $this->buildProductPayload($product);
            }

            if ($product->getCategories() === null || $product->getCategories()->count() === 0) {
                $issues['missingCategory'][] = $this->buildProductPayload($product);
            }

            if ($checkMissingManufacturer && $product->getManufacturerId() === null) {
                $issues['missingManufacturer'][] = $this->buildProductPayload($product);
            }

            if ($this->hasMissingPrice($product)) {
                $issues['missingPrice'][] = $this->buildProductPayload($product);
            }

            if ($checkMissingTranslations) {
                $missingLanguages = $this->getMissingTranslationLanguages($product, $languages, $variantAuditMode);

                if ($missingLanguages !== []) {
                    $issues['missingTranslation'][] = $this->buildProductPayload($product, [
                        'missingLanguages' => implode(', ', $missingLanguages),
                    ]);
                }
            }
        }

        return [
            'meta' => [
                'scannedProducts' => $products->count(),
                'productLimit' => $limit,
                'variantAuditMode' => $variantAuditMode,
            ],
            'totals' => [
                'missingDescription' => \count($issues['missingDescription']),
                'missingCoverImage' => \count($issues['missingCoverImage']),
                'inactiveProducts' => \count($issues['inactiveProducts']),
                'outOfStockProducts' => \count($issues['outOfStockProducts']),
                'missingMetaTitle' => \count($issues['missingMetaTitle']),
                'missingCategory' => \count($issues['missingCategory']),
                'missingManufacturer' => \count($issues['missingManufacturer']),
                'missingPrice' => \count($issues['missingPrice']),
                'missingTranslation' => \count($issues['missingTranslation']),
                'totalIssues' => array_sum([
                    \count($issues['missingDescription']),
                    \count($issues['missingCoverImage']),
                    \count($issues['inactiveProducts']),
                    \count($issues['outOfStockProducts']),
                    \count($issues['missingMetaTitle']),
                    \count($issues['missingCategory']),
                    \count($issues['missingManufacturer']),
                    \count($issues['missingPrice']),
                    \count($issues['missingTranslation']),
                ]),
            ],
            'issues' => $issues,
        ];
    }

    private function loadProducts(Context $context, int $limit, string $variantAuditMode): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addAssociation('categories');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('translations');

        $searchContext = clone $context;

        if ($variantAuditMode === self::VARIANT_AUDIT_MODE_EFFECTIVE) {
            $searchContext->setConsiderInheritance(true);
        } else {
            $searchContext->setConsiderInheritance(false);
        }

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $searchContext)->getEntities();

        return $products;
    }

    private function loadLanguages(Context $context): LanguageCollection
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translationCode');

        /** @var LanguageCollection $languages */
        $languages = $this->languageRepository->search($criteria, $context)->getEntities();

        return $languages;
    }

    private function hasMissingPrice(ProductEntity $product): bool
    {
        $prices = $product->getPrice();

        if (!$prices instanceof PriceCollection || $prices->count() === 0) {
            return true;
        }

        foreach ($prices as $price) {
            $gross = $price->getGross();
            $net = $price->getNet();

            if ($gross !== null && $gross > 0 && $net !== null && $net > 0) {
                return false;
            }
        }

        return true;
    }

    private function getMissingTranslationLanguages(
        ProductEntity $product,
        LanguageCollection $languages,
        string $variantAuditMode
    ): array {
        $missingLanguages = [];

        if ($variantAuditMode === self::VARIANT_AUDIT_MODE_EFFECTIVE) {
            $translated = $product->getTranslated();

            $effectiveName = trim((string) ($translated['name'] ?? ''));
            $effectiveDescription = trim((string) ($translated['description'] ?? ''));

            foreach ($languages as $language) {
                $locale = $language->getTranslationCode();
                $languageLabel = $locale?->getCode() ?? $language->getId();

                if ($effectiveName === '' || $effectiveDescription === '') {
                    $missingLanguages[] = $languageLabel;
                }
            }

            return $missingLanguages;
        }

        $translations = $product->getTranslations();

        foreach ($languages as $language) {
            $languageId = $language->getId();
            $locale = $language->getTranslationCode();
            $languageLabel = $locale?->getCode() ?? $languageId;

            $translation = $translations?->get($languageId);

            if ($translation === null) {
                $missingLanguages[] = $languageLabel;
                continue;
            }

            $name = trim((string) $translation->getName());
            $description = trim((string) $translation->getDescription());

            if ($name === '' || $description === '') {
                $missingLanguages[] = $languageLabel;
            }
        }

        return $missingLanguages;
    }

    private function buildProductPayload(ProductEntity $product, array $extra = []): array
    {
        $translated = $product->getTranslated();
        $name = (string) ($translated['name'] ?? $product->getProductNumber() ?? $product->getId());

        return array_merge([
            'id' => $product->getId(),
            'parentId' => $product->getParentId(),
            'name' => $name,
            'productNumber' => $product->getProductNumber(),
            'active' => $product->getActive(),
            'stock' => $product->getStock(),
        ], $extra);
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