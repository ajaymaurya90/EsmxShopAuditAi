<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\BrokenLink;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class BrokenLinkAuditService
{
    public function __construct(
        #[Autowire(service: 'language.repository')]
        private EntityRepository $languageRepository,

        #[Autowire(service: 'product.repository')]
        private EntityRepository $productRepository,

        #[Autowire(service: 'category.repository')]
        private EntityRepository $categoryRepository,

        #[Autowire(service: 'cms_page.repository')]
        private EntityRepository $cmsPageRepository,

        private BrokenLinkExtractor $extractor,
        private BrokenLinkChecker $checker,
        private LoggerInterface $logger,
        private RequestStack $requestStack
    ) {}

    /**
     * Main scan runner
     */
    public function run(
        Context $context,
        int $limit = 500,
        int $timeout = 5,
        bool $checkExternal = false,
        ?array $scanOptions = null
    ): array
    {
        $brokenLinks   = [];
        $checkedUrls   = [];   // Cache URL check results (avoid duplicate HTTP calls)
        $reportedItems = [];   // Prevent duplicate findings
        $totalChecked  = 0;
        $enabledSources = $this->resolveEnabledSources($scanOptions);
        $sourceStats = [
            'product_description' => ['items' => 0, 'links' => 0],
            'category_description' => ['items' => 0, 'links' => 0],
            'cms_content' => ['items' => 0, 'links' => 0],
        ];

        $this->logger->info('Broken link audit source selection', [
            'receivedBrokenLinksScanOptions' => $scanOptions['brokenLinks'] ?? null,
            'enabledSources' => array_keys(array_filter($enabledSources)),
            'sources' => $enabledSources,
        ]);

        if (!\in_array(true, $enabledSources, true)) {
            return ['broken_links' => []];
        }

        /**
         * Load all data ONCE (performance optimization)
         */
        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $languages = $this->languageRepository->search($criteria, $context);

        /**
         * Iterate per language (logical separation)
         * @var LanguageEntity $language
         */
        foreach ($languages->getEntities() as $language) {

            $languageId   = $language->getId();
            $locale = $language->getLocale();
            $languageName = $locale ? $locale->getCode() : $languageId;

            $languageContext = clone $context;
            $languageContext->assign(['languageIdChain' => [$languageId]]);

            /**
             * ==============================
             * PRODUCTS
             * ==============================
             */
            if ($enabledSources['product_description']) {
                $productCriteria = new Criteria();
                $productCriteria->setLimit($limit);
                $productCriteria->addFilter(new EqualsFilter('product.active', true));
                $productResult = $this->productRepository->search($productCriteria, $languageContext);

                /** @var ProductEntity $product */
                foreach ($productResult->getEntities() as $product) {

                    // IMPORTANT: now this is language-specific
                    $description = $product->getTranslation('description');

                    if (empty($description)) {
                        continue;
                    }

                    $links = $this->extractor->extractLinks($description);
                    $sourceStats['product_description']['items']++;
                    $sourceStats['product_description']['links'] += \count($links);

                    if (!$this->processLinks($links, [
                        'languageId'   => $languageId,
                        'languageName' => $languageName,
                        'id'           => $product->getId(),
                        'entity'       => 'product',
                        'name'         => $product->getTranslation('name'),
                        'source'       => 'product_description',
                    ], $brokenLinks, $checkedUrls, $reportedItems, $totalChecked, $limit, $timeout, $checkExternal)) {
                        break;
                    }
                }
            }

            /**
             * ==============================
             * CATEGORIES
             * ==============================
             */
            if ($enabledSources['category_description']) {
                $categoryCriteria = new Criteria();
                $categoryCriteria->setLimit($limit);
                $categoryCriteria->addAssociation('translations');
                $categoryResult = $this->categoryRepository->search($categoryCriteria, $languageContext);

                /** @var CategoryEntity $category */
                foreach ($categoryResult->getEntities() as $category) {

                    $translated = $category->getTranslated();
                    $description = $translated['description'] ?? null;

                    if (empty($description)) {
                        continue;
                    }

                    $links = $this->extractor->extractLinks($description);
                    $sourceStats['category_description']['items']++;
                    $sourceStats['category_description']['links'] += \count($links);

                    if (!$this->processLinks($links, [
                        'languageId'   => $languageId,
                        'languageName' => $languageName,
                        'id'           => $category->getId(),
                        'entity'       => 'category',
                        'name'         => $translated['name'] ?? $category->getId(),
                        'source'       => 'category_description',
                    ], $brokenLinks, $checkedUrls, $reportedItems, $totalChecked, $limit, $timeout, $checkExternal)) {
                        break;
                    }
                }
            }

            /**
             * ==============================
             * CMS PAGES
             * ==============================
             */
            if ($enabledSources['cms_content']) {
                $cmsCriteria = new Criteria();
                $cmsCriteria->setLimit($limit);
                $cmsCriteria->addAssociation('sections.blocks.slots.translations');
                $cmsPages = $this->cmsPageRepository->search($cmsCriteria, $context);
                /** @var CmsPageEntity $page */
                foreach ($cmsPages->getEntities() as $page) {

                    $uniquePageUrls = [];

                    foreach ($page->getSections() ?? [] as $section) {
                        foreach ($section->getBlocks() ?? [] as $block) {
                            foreach ($block->getSlots() ?? [] as $slot) {

                                $config = $this->getSlotConfigForLanguage($slot, $languageId);

                                if (!$config) {
                                    continue;
                                }

                                foreach ($config as $field) {

                                    $value = $field['value'] ?? null;

                                    if (!is_string($value) || trim($value) === '') {
                                        continue;
                                    }

                                    foreach ($this->extractor->extractLinks($value) as $url) {

                                        $normalized = $this->normalizeUrl($url);

                                        if ($normalized === '') {
                                            continue;
                                        }

                                        // Deduplicate per page
                                        $uniquePageUrls[$normalized] = $url;
                                    }
                                }
                            }
                        }
                    }

                    // Process unique URLs
                    if ($uniquePageUrls !== []) {
                        $sourceStats['cms_content']['items']++;
                        $sourceStats['cms_content']['links'] += \count($uniquePageUrls);
                    }

                    foreach ($uniquePageUrls as $originalUrl) {

                        if (!$this->checkAndAppend(
                            $originalUrl,
                            [
                                'languageId'   => $languageId,
                                'languageName' => $languageName,
                                'id'           => $page->getId(),
                                'entity'       => 'cms_page',
                                'name'         => $page->getName() ?? $page->getId(),
                                'source'       => 'cms_content',
                            ],
                            $brokenLinks,
                            $checkedUrls,
                            $reportedItems,
                            $totalChecked,
                            $limit,
                            $timeout,
                            $checkExternal
                        )) {
                            break;
                        }
                    }
                }
            }
        }

        $this->logger->info('Broken link audit completed source scan', [
            'enabledSources' => array_keys(array_filter($enabledSources)),
            'sourceStats' => $sourceStats,
            'totalChecked' => $totalChecked,
            'brokenLinkCount' => \count($brokenLinks),
        ]);

        return ['broken_links' => $brokenLinks];
    }

    private function resolveEnabledSources(?array $scanOptions): array
    {
        $checks = $scanOptions['brokenLinks']['checks'] ?? [];

        return [
            'product_description' => $this->isSourceEnabled($checks, 'product_description'),
            'category_description' => $this->isSourceEnabled($checks, 'category_description'),
            'cms_content' => $this->isSourceEnabled($checks, 'cms_content'),
        ];
    }

    private function isSourceEnabled(array $checks, string $source): bool
    {
        if (!\array_key_exists($source, $checks)) {
            return true;
        }

        return (bool) $checks[$source];
    }

    /**
     * Reusable link processor
     */
    private function processLinks(
        array $links,
        array $payload,
        array &$brokenLinks,
        array &$checkedUrls,
        array &$reportedItems,
        int &$totalChecked,
        int $limit,
        int $timeout,
        bool $checkExternal
    ): bool {
        foreach ($links as $url) {
            if (!$this->checkAndAppend(
                $url,
                $payload,
                $brokenLinks,
                $checkedUrls,
                $reportedItems,
                $totalChecked,
                $limit,
                $timeout,
                $checkExternal
            )) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check URL + append if broken
     */
    private function checkAndAppend(
        string $url,
        array $payload,
        array &$brokenLinks,
        array &$checkedUrls,
        array &$reportedItems,
        int &$totalChecked,
        int $limit,
        int $timeout,
        bool $checkExternal
    ): bool {
        $normalized = $this->normalizeUrl($url);
        $isExternal = $this->isExternalUrl($url);

        if ($normalized === '') {
            $this->logger->debug('Broken link audit skipped empty normalized URL', [
                'rawUrl' => $url,
                'normalizedUrl' => $normalized,
                'source' => $payload['source'] ?? null,
                'entity' => $payload['entity'] ?? null,
            ]);

            return true;
        }

        if ($isExternal && !$checkExternal) {
            $this->logger->debug('Broken link audit skipped external URL because external checks are disabled', [
                'rawUrl' => $url,
                'normalizedUrl' => $normalized,
                'isExternal' => true,
                'checkExternal' => $checkExternal,
                'skipped_external_disabled' => true,
                'totalCheckedIncremented' => false,
                'source' => $payload['source'] ?? null,
                'entity' => $payload['entity'] ?? null,
            ]);

            return true;
        }

        if ($totalChecked >= $limit && !isset($checkedUrls[$normalized])) {
            $this->logger->debug('Broken link audit stopped at configured link limit', [
                'rawUrl' => $url,
                'normalizedUrl' => $normalized,
                'isExternal' => $isExternal,
                'checkExternal' => $checkExternal,
                'limit' => $limit,
                'totalChecked' => $totalChecked,
                'totalCheckedIncremented' => false,
                'source' => $payload['source'] ?? null,
                'entity' => $payload['entity'] ?? null,
            ]);

            return false;
        }

        $totalCheckedBefore = $totalChecked;

        if (!isset($checkedUrls[$normalized])) {
            $checkedUrls[$normalized] = $this->checker->check($url, $timeout);
            $totalChecked++;
        }

        $check = $checkedUrls[$normalized];

        $this->logger->debug('Broken link audit checked URL', [
            'rawUrl' => $url,
            'normalizedUrl' => $normalized,
            'isExternal' => $isExternal,
            'checkExternal' => $checkExternal,
            'skipped_external_disabled' => false,
            'totalCheckedIncremented' => $totalChecked > $totalCheckedBefore,
            'totalChecked' => $totalChecked,
            'status' => $check['status'] ?? null,
            'error' => $check['error'] ?? null,
            'source' => $payload['source'] ?? null,
            'entity' => $payload['entity'] ?? null,
        ]);

        if ($this->checker->isBroken($check)) {

            $key = implode('|', [
                $normalized,
                $payload['entity'] ?? '',
                $payload['id'] ?? '',
                $payload['languageId'] ?? '',
                $payload['source'] ?? ''
            ]);

            if (!isset($reportedItems[$key])) {

                $reportedItems[$key] = true;

                $brokenLinks[] = array_merge([
                    'url'    => $normalized,
                    'status' => $check['status'],
                    'error'  => $check['error'],
                ], $payload);
            }
        }

        return true;
    }

    private function isExternalUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            return false;
        }

        $currentRequest = $this->requestStack->getCurrentRequest();
        $currentHost = $currentRequest?->getHost();

        if (!\is_string($currentHost) || $currentHost === '') {
            return true;
        }

        return mb_strtolower($host) !== mb_strtolower($currentHost);
    }

    /**
     * Normalize URL
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $url = rtrim($url, '/');

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($url);

            return ($parsed['path'] ?? '') .
                (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        return $url;
    }

    /**
     * Get CMS slot config for specific language (avoid fallback duplication)
     */
    private function getSlotConfigForLanguage($slot, string $languageId): ?array
    {
        foreach ($slot->getTranslations() ?? [] as $translation) {

            if ($translation->getLanguageId() !== $languageId) {
                continue;
            }

            $config = $translation->getConfig();

            if (empty($config) || $translation->getUpdatedAt() === null) {
                return null;
            }

            return $config;
        }

        return null;
    }
}
