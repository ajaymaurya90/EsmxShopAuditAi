<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit;

use EsmxShopAuditAi\Service\Audit\Seo\SeoAuditResult;
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
    private const int DEFAULT_LIMIT = 100;
    private const string VARIANT_AUDIT_MODE_EFFECTIVE = 'effective';
    private const string VARIANT_AUDIT_MODE_RAW = 'raw';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $languageRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function buildProductAuditSummary(Context $context): array
    {
        $limit = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.auditProductLimit') ?? self::DEFAULT_LIMIT);

        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $variantAuditMode = $this->getVariantAuditMode();

        $enableAudit = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.enableAudit') ?? true);
        $checkMissingManufacturer = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkMissingManufacturer') ?? true);
        $checkMissingTranslations = (bool) ($this->systemConfigService->get('EsmxShopAuditAi.config.checkMissingTranslations') ?? true);

        if ($enableAudit !== true) {
            return [
                'meta' => [
                    'scannedProducts' => 0,
                    'productLimit' => $limit,
                    'variantAuditMode' => $variantAuditMode,
                    'seo' => $this->buildDefaultSeoMeta(),
                ],
                'totals' => [
                    'missingCoverImage' => 0,
                    'inactiveProducts' => 0,
                    'outOfStockProducts' => 0,
                    'missingCategory' => 0,
                    'missingManufacturer' => 0,
                    'missingPrice' => 0,
                    'missingTranslation' => 0,
                    'totalIssues' => 0,
                ],
                'issues' => [
                    'missingCoverImage' => [],
                    'inactiveProducts' => [],
                    'outOfStockProducts' => [],
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
            'missingCoverImage' => [],
            'inactiveProducts' => [],
            'outOfStockProducts' => [],
            'missingCategory' => [],
            'missingManufacturer' => [],
            'missingPrice' => [],
            'missingTranslation' => [],
        ];

        /** @var ProductEntity $product */
        foreach ($products as $product) {

            if ($product->getCoverId() === null) {
                $issues['missingCoverImage'][] = $this->buildProductPayload($product);
            }

            if ($product->getActive() !== true) {
                $issues['inactiveProducts'][] = $this->buildProductPayload($product);
            }

            if (($product->getStock() ?? 0) <= 0) {
                $issues['outOfStockProducts'][] = $this->buildProductPayload($product);
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
                'seo' => $this->buildDefaultSeoMeta(),
            ],
            'totals' => [
                'missingCoverImage' => \count($issues['missingCoverImage']),
                'inactiveProducts' => \count($issues['inactiveProducts']),
                'outOfStockProducts' => \count($issues['outOfStockProducts']),
                'missingCategory' => \count($issues['missingCategory']),
                'missingManufacturer' => \count($issues['missingManufacturer']),
                'missingPrice' => \count($issues['missingPrice']),
                'missingTranslation' => \count($issues['missingTranslation']),
                'totalIssues' => $this->calculateTotalIssues([
                    'missingCoverImage' => \count($issues['missingCoverImage']),
                    'inactiveProducts' => \count($issues['inactiveProducts']),
                    'outOfStockProducts' => \count($issues['outOfStockProducts']),
                    'missingCategory' => \count($issues['missingCategory']),
                    'missingManufacturer' => \count($issues['missingManufacturer']),
                    'missingPrice' => \count($issues['missingPrice']),
                    'missingTranslation' => \count($issues['missingTranslation']),
                ]),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * Merges SEO audit issues and KPI data into the product audit summary.
     */
    public function mergeSeoAuditResultIntoSummary(array $auditSummary, SeoAuditResult $seoAuditResult): array
    {
        $auditSummary = $this->mergeIssuesIntoSummary($auditSummary, $seoAuditResult->getIssues());
        $auditSummary = $this->mergeSeoKpiIntoSummary($auditSummary, $seoAuditResult);

        return $auditSummary;
    }

    /**
     * Merges additional issue groups into an existing audit summary and recalculates total issues.
     *
     * @param array<string, mixed> $auditSummary
     * @param array<string, array<string, mixed>> $issues
     *
     * @return array<string, mixed>
     */
    public function mergeIssuesIntoSummary(array $auditSummary, array $issues): array
    {
        foreach ($issues as $code => $definition) {
            $items = $definition['items'] ?? [];

            $auditSummary['issues'][$code] = \is_array($items) ? $items : [];
            $auditSummary['totals'][$code] = \is_array($items) ? \count($items) : 0;
        }

        $auditSummary['totals']['totalIssues'] = $this->calculateTotalIssues($auditSummary['totals'] ?? []);

        return $auditSummary;
    }

    /**
     * Adds SEO KPI values to the audit summary meta block.
     */
    public function mergeSeoKpiIntoSummary(array $auditSummary, SeoAuditResult $seoAuditResult): array
    {
        $auditSummary['meta']['seo'] = $seoAuditResult->getKpi()->toArray();

        return $auditSummary;
    }

    /**
     * Recalculates the total number of issues across all active issue groups.
     */
    public function calculateTotalIssues(array $totals): int
    {
        $totalIssues = 0;

        foreach ($totals as $key => $value) {
            if ($key === 'totalIssues') {
                continue;
            }

            $totalIssues += (int) $value;
        }

        return $totalIssues;
    }

    /**
     * @return array<string, int|float>
     */
    private function buildDefaultSeoMeta(): array
    {
        return [
            'totalProducts' => 0,
            'productsNeedingImprovement' => 0,
            'averageOverallScore' => 0,
            'improvementThreshold' => 0,
            'improvementRate' => 0.0,
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
            if ($price->getGross() > 0 || $price->getNet() > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function getMissingTranslationLanguages(
        ProductEntity $product,
        LanguageCollection $languages,
        string $variantAuditMode
    ): array {
        $translations = $product->getTranslations();
        $missingLanguages = [];

        foreach ($languages as $language) {
            $languageId = $language->getId();

            if ($languageId === null) {
                continue;
            }

            $translation = $translations?->get($languageId);

            if ($translation === null) {
                $missingLanguages[] = $this->buildLanguageLabel($language);
                continue;
            }

            $translatedName = trim((string) ($translation->getName() ?? ''));

            if ($translatedName === '' && $variantAuditMode === self::VARIANT_AUDIT_MODE_RAW) {
                $missingLanguages[] = $this->buildLanguageLabel($language);
            }
        }

        return $missingLanguages;
    }

    private function buildLanguageLabel($language): string
    {
        $translationCode = $language->getTranslationCode();
        $name = $translationCode?->getName();
        $code = $translationCode?->getCode();

        if ($name !== null && $code !== null) {
            return sprintf('%s (%s)', $name, $code);
        }

        if ($name !== null) {
            return $name;
        }

        if ($code !== null) {
            return $code;
        }

        return (string) $language->getId();
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

    private function buildProductPayload(ProductEntity $product, array $extra = []): array
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