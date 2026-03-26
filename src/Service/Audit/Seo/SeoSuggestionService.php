<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use Shopware\Core\Content\Product\ProductEntity;

class SeoSuggestionService
{
    private const DEFAULT_META_TITLE_MAX_LENGTH = 65;
    private const DEFAULT_META_DESCRIPTION_MAX_LENGTH = 160;
    private const SHORT_PRODUCT_NAME_THRESHOLD = 12;
    private const MIN_USEFUL_DESCRIPTION_LENGTH = 40;

    public function suggestForWeakMetaTitle(ProductEntity $product, ProductSeoScoreResult $scoreResult): array
    {
        $translated = $product->getTranslated();

        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));
        $description = trim(strip_tags((string) ($translated['description'] ?? '')));

        $reason = $this->resolveWeakMetaTitleReason($productName, $currentMetaTitle, $scoreResult);
        $suggestedMetaTitle = $this->generateMetaTitleSuggestion($product);
        $suggestedMetaDescription = $this->generateMetaDescriptionSuggestion($product);

        return [
            'status' => 'pending_review',
            'reason' => $reason,
            'currentMetaTitle' => $currentMetaTitle,
            'suggestedMetaTitle' => $suggestedMetaTitle,
            'currentMetaDescription' => $currentMetaDescription,
            'suggestedMetaDescription' => $suggestedMetaDescription,
            'productName' => $productName,
            'descriptionExcerpt' => $this->truncateText($description, 180),
            'metaTitleScore' => $scoreResult->getMetaTitleScore(),
            'overallSeoScore' => $scoreResult->getOverallScore(),
            'qualityLevel' => $scoreResult->getQualityLevel(),
        ];
    }

    public function suggestForMissingMetaDescription(ProductEntity $product, ProductSeoScoreResult $scoreResult): array
    {
        $translated = $product->getTranslated();

        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));
        $description = trim(strip_tags((string) ($translated['description'] ?? '')));

        return [
            'status' => 'pending_review',
            'reason' => 'Meta description is missing',
            'currentMetaTitle' => $currentMetaTitle,
            'suggestedMetaTitle' => $this->generateMetaTitleSuggestion($product),
            'currentMetaDescription' => $currentMetaDescription,
            'suggestedMetaDescription' => $this->generateMetaDescriptionSuggestion($product),
            'productName' => $productName,
            'descriptionExcerpt' => $this->truncateText($description, 180),
            'metaDescriptionScore' => $scoreResult->getMetaDescriptionScore(),
            'overallSeoScore' => $scoreResult->getOverallScore(),
            'qualityLevel' => $scoreResult->getQualityLevel(),
        ];
    }

    public function generateMetaTitleSuggestion(ProductEntity $product): string
    {
        $translated = $product->getTranslated();

        $productName = trim((string) ($translated['name'] ?? ''));
        $manufacturerName = $this->extractManufacturerName($product);
        $categoryName = $this->extractPrimaryCategoryName($product);

        if ($productName === '') {
            return 'Discover Product Online';
        }

        $isShortProductName = mb_strlen($productName) < self::SHORT_PRODUCT_NAME_THRESHOLD;

        $candidates = [];

        if ($isShortProductName) {
            $candidates[] = $this->buildShortNameMetaTitleWithCategoryAndBrand($productName, $categoryName, $manufacturerName);
            $candidates[] = $this->buildShortNameMetaTitleWithCategory($productName, $categoryName);
            $candidates[] = $this->buildShortNameMetaTitleWithBrand($productName, $manufacturerName);
            $candidates[] = sprintf('Buy %s Online', $productName);
            $candidates[] = sprintf('%s | Shop Online', $productName);
        } else {
            $candidates[] = $this->buildMetaTitleFromProductAndBrand($productName, $manufacturerName);
            $candidates[] = $this->buildMetaTitleFromProductAndCategory($productName, $categoryName);
            $candidates[] = sprintf('Buy %s Online', $productName);
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeWhitespace((string) $candidate);

            if ($normalized === '') {
                continue;
            }

            if (mb_strtolower($normalized) === mb_strtolower($productName)) {
                continue;
            }

            if (mb_strlen($normalized) <= self::DEFAULT_META_TITLE_MAX_LENGTH) {
                return $normalized;
            }
        }

        $fallback = sprintf('Buy %s Online', $productName);

        if (mb_strlen($fallback) <= self::DEFAULT_META_TITLE_MAX_LENGTH) {
            return $fallback;
        }

        return $this->truncateText($fallback, self::DEFAULT_META_TITLE_MAX_LENGTH);
    }

    public function generateMetaDescriptionSuggestion(ProductEntity $product): string
    {
        $translated = $product->getTranslated();

        $productName = trim((string) ($translated['name'] ?? ''));
        $description = trim(strip_tags((string) ($translated['description'] ?? '')));
        $manufacturerName = $this->extractManufacturerName($product);
        $categoryName = $this->extractPrimaryCategoryName($product);

        $descriptionBase = $this->buildMetaDescriptionBase(
            $productName,
            $description,
            $manufacturerName,
            $categoryName
        );

        return $this->truncateText($descriptionBase, self::DEFAULT_META_DESCRIPTION_MAX_LENGTH);
    }

    private function resolveWeakMetaTitleReason(
        string $productName,
        string $currentMetaTitle,
        ProductSeoScoreResult $scoreResult
    ): string {
        if ($currentMetaTitle === '') {
            return 'Meta title is missing';
        }

        if ($productName !== '' && mb_strtolower($currentMetaTitle) === mb_strtolower($productName)) {
            return 'Meta title is identical to the product name';
        }

        $length = mb_strlen($currentMetaTitle);

        if ($length < 30) {
            return 'Meta title is too short';
        }

        if ($length > self::DEFAULT_META_TITLE_MAX_LENGTH) {
            return 'Meta title is too long';
        }

        if ($scoreResult->getMetaTitleScore() < 70) {
            return 'Meta title needs SEO improvement';
        }

        return 'Meta title should be improved';
    }

    private function buildMetaTitleFromProductAndBrand(string $productName, string $manufacturerName): string
    {
        if ($productName === '') {
            return '';
        }

        if ($manufacturerName !== '') {
            return sprintf('%s | %s', $productName, $manufacturerName);
        }

        return sprintf('Buy %s Online', $productName);
    }

    private function buildMetaTitleFromProductAndCategory(string $productName, string $categoryName): string
    {
        if ($productName === '') {
            return '';
        }

        if ($categoryName !== '') {
            return sprintf('%s - %s', $productName, $categoryName);
        }

        return sprintf('Buy %s Online', $productName);
    }

    private function buildShortNameMetaTitleWithCategoryAndBrand(
        string $productName,
        string $categoryName,
        string $manufacturerName
    ): string {
        if ($productName === '') {
            return '';
        }

        if ($categoryName !== '' && $manufacturerName !== '') {
            return sprintf('%s - %s | %s', $productName, $categoryName, $manufacturerName);
        }

        return '';
    }

    private function buildShortNameMetaTitleWithCategory(string $productName, string $categoryName): string
    {
        if ($productName === '') {
            return '';
        }

        if ($categoryName !== '') {
            return sprintf('%s - %s', $productName, $categoryName);
        }

        return '';
    }

    private function buildShortNameMetaTitleWithBrand(string $productName, string $manufacturerName): string
    {
        if ($productName === '') {
            return '';
        }

        if ($manufacturerName !== '') {
            return sprintf('%s | %s', $productName, $manufacturerName);
        }

        return '';
    }

    private function buildMetaDescriptionBase(
        string $productName,
        string $description,
        string $manufacturerName,
        string $categoryName
    ): string {
        $cleanDescription = $this->normalizeWhitespace($description);

        if (mb_strlen($cleanDescription) >= self::MIN_USEFUL_DESCRIPTION_LENGTH) {
            if ($productName !== '') {
                return sprintf(
                    'Discover %s. %s',
                    $productName,
                    $this->ensureSentenceEnding($this->truncateText($cleanDescription, 120))
                );
            }

            return $this->ensureSentenceEnding($this->truncateText($cleanDescription, 140));
        }

        $parts = [];

        if ($productName !== '') {
            $parts[] = sprintf('Discover %s', $productName);
        } else {
            $parts[] = 'Discover this product';
        }

        if ($manufacturerName !== '') {
            $parts[] = sprintf('from %s', $manufacturerName);
        }

        if ($categoryName !== '') {
            $parts[] = sprintf('in our %s selection', $categoryName);
        }

        $baseSentence = $this->ensureSentenceEnding(implode(' ', $parts));

        return $baseSentence . ' Explore features, details, and availability in our store.';
    }

    private function extractManufacturerName(ProductEntity $product): string
    {
        $manufacturer = $product->getManufacturer();

        if ($manufacturer === null) {
            return '';
        }

        $translatedName = $manufacturer->getTranslation('name');

        if (\is_string($translatedName) && trim($translatedName) !== '') {
            return trim($translatedName);
        }

        return trim((string) ($manufacturer->getName() ?? ''));
    }

    private function extractPrimaryCategoryName(ProductEntity $product): string
    {
        $categories = $product->getCategories();

        if ($categories === null || $categories->count() === 0) {
            return '';
        }

        $firstCategory = $categories->first();

        if ($firstCategory === null) {
            return '';
        }

        $translatedName = $firstCategory->getTranslation('name');

        if (\is_string($translatedName) && trim($translatedName) !== '') {
            return trim($translatedName);
        }

        return trim((string) ($firstCategory->getName() ?? ''));
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = $this->normalizeWhitespace($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, max(0, $maxLength - 3));

        return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '...';
    }

    private function ensureSentenceEnding(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (preg_match('/[.!?]$/', $text) === 1) {
            return $text;
        }

        return $text . '.';
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }
}