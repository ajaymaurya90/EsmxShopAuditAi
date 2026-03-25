<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoAuditDataProvider;
use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use EsmxShopAuditAi\Service\Audit\Seo\SeoSuggestionService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductNameRule extends AbstractScoredProductSeoAuditRule
{
    private const int DEFAULT_MIN_LENGTH = 20;

    public function __construct(
        SystemConfigService $systemConfigService,
        ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly SeoSuggestionService $seoSuggestionService
    ) {
        parent::__construct($systemConfigService, $productSeoAuditDataProvider);
    }

    public function getCode(): string
    {
        return 'product_name';
    }

    public function getTitle(): string
    {
        return 'Products with name issues';
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
        return (bool) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.checkProductName'
        ) ?? true);
    }

    public function auditProducts(ProductCollection $products): array
    {
        return [];
    }

    /**
     * @param array<string, ProductSeoScoreResult> $scoreResults
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditProductsWithScores(ProductCollection $products, array $scoreResults): array
    {
        $result = [];
        $seen = [];
        $minLength = $this->getMinLength();

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $translated = $product->getTranslated();
            $productName = trim((string) ($translated['name'] ?? ''));
            $metaTitle = trim((string) ($translated['metaTitle'] ?? ''));
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));
            $description = trim(strip_tags((string) ($translated['description'] ?? '')));

            $scoreResult = $this->getScoreResult($product, $scoreResults);

            if ($scoreResult === null) {
                continue;
            }

            $reason = $this->resolveReason($productName, $minLength);

            if ($reason === null) {
                continue;
            }

            if ($this->shouldSkipInEffectiveMode($product, 'name', $seen)) {
                continue;
            }

            $suggestion = $this->buildNameSuggestion(
                $product,
                $scoreResult,
                $productName,
                $metaTitle,
                $metaDescription,
                $description,
                $minLength,
                $reason
            );

            $result[] = $this->buildSeoIssuePayload($product, 'name', $scoreResult->getOverallScore(), [
                'productNameLength' => mb_strlen($productName),
                'minProductNameLength' => $minLength,
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'descriptionExcerpt' => $this->truncateText($description, 180),
                'overallSeoScore' => $scoreResult->getOverallScore(),
                'qualityLevel' => $scoreResult->getQualityLevel(),
                'reason' => $reason,
                'suggestion' => $suggestion,
            ]);
        }

        return $result;
    }

    private function resolveReason(string $productName, int $minLength): ?string
    {
        if ($productName === '') {
            return 'Product name is missing';
        }

        if (mb_strlen($productName) < $minLength) {
            return sprintf(
                'Product name is too short (%d chars, recommended at least %d)',
                mb_strlen($productName),
                $minLength
            );
        }

        return null;
    }

    private function buildNameSuggestion(
        ProductEntity $product,
        ProductSeoScoreResult $scoreResult,
        string $productName,
        string $metaTitle,
        string $metaDescription,
        string $description,
        int $minLength,
        string $reason
    ): array {
        $suggestedMetaTitle = $this->seoSuggestionService->generateMetaTitleSuggestion($product);
        $suggestedMetaDescription = $this->seoSuggestionService->generateMetaDescriptionSuggestion($product);

        return [
            'status' => 'pending_review',
            'reason' => $reason,
            'productName' => $productName,
            'currentMetaTitle' => $metaTitle,
            'currentMetaDescription' => $metaDescription,
            'currentDescriptionExcerpt' => $this->truncateText($description, 180),
            'minProductNameLength' => $minLength,
            'seoScore' => $scoreResult->getOverallScore(),
            'overallSeoScore' => $scoreResult->getOverallScore(),
            'qualityLevel' => $scoreResult->getQualityLevel(),
            'suggestedMetaTitle' => $suggestedMetaTitle,
            'suggestedMetaDescription' => $suggestedMetaDescription,
        ];
    }

    private function getMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.minProductTitleLength'
        ) ?? self::DEFAULT_MIN_LENGTH);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        $text = (string) $text;

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, max(0, $maxLength - 3));

        return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '...';
    }
}