<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoAuditDataProvider;
use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use EsmxShopAuditAi\Service\Audit\Seo\SeoSuggestionService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductDescriptionRule extends AbstractScoredProductSeoAuditRule
{
    private const DEFAULT_MIN_LENGTH = 80;

    public function __construct(
        SystemConfigService $systemConfigService,
        ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly SeoSuggestionService $seoSuggestionService
    ) {
        parent::__construct($systemConfigService, $productSeoAuditDataProvider);
    }

    public function getCode(): string
    {
        return 'product_description';
    }

    public function getTitle(): string
    {
        return 'Products with description issues';
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
        return (bool) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.checkProductDescription'
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
            $scoreResult = $this->getScoreResult($product, $scoreResults);

            if ($scoreResult === null) {
                continue;
            }

            $translated = $product->getTranslated();
            $description = trim(strip_tags((string) ($translated['description'] ?? '')));
            $metaTitle = trim((string) ($translated['metaTitle'] ?? ''));
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

            $reason = $this->resolveReason($description, $minLength);

            if ($reason === null) {
                continue;
            }

            if ($this->shouldSkipInEffectiveMode($product, 'description', $seen)) {
                continue;
            }

            $suggestion = $this->buildDescriptionSuggestion(
                $product,
                $scoreResult,
                $description,
                $metaTitle,
                $metaDescription,
                $minLength,
                $reason
            );

            $result[] = $this->buildSeoIssuePayload($product, 'description', $scoreResult->getDescriptionScore(), [
                'descriptionExcerpt' => $this->truncateText($description, 180),
                'descriptionLength' => mb_strlen($description),
                'minDescriptionLength' => $minLength,
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'descriptionScore' => $scoreResult->getDescriptionScore(),
                'overallSeoScore' => $scoreResult->getOverallScore(),
                'qualityLevel' => $scoreResult->getQualityLevel(),
                'reason' => $reason,
                'suggestion' => $suggestion,
            ]);
        }

        return $result;
    }

    private function resolveReason(string $description, int $minLength): ?string
    {
        if ($description === '') {
            return 'Description is missing';
        }

        $length = mb_strlen($description);

        if ($length < $minLength) {
            return sprintf(
                'Description is too short (%d chars, recommended at least %d)',
                $length,
                $minLength
            );
        }

        return null;
    }

    private function buildDescriptionSuggestion(
        ProductEntity $product,
        ProductSeoScoreResult $scoreResult,
        string $description,
        string $metaTitle,
        string $metaDescription,
        int $minLength,
        string $reason
    ): array {
        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));

        return [
            'status' => 'pending_review',
            'reason' => $reason,
            'productName' => $productName,
            'currentDescriptionExcerpt' => $this->truncateText($description, 180),
            'currentMetaTitle' => $metaTitle,
            'currentMetaDescription' => $metaDescription,
            'minDescriptionLength' => $minLength,
            'seoScore' => $scoreResult->getDescriptionScore(),
            'descriptionScore' => $scoreResult->getDescriptionScore(),
            'overallSeoScore' => $scoreResult->getOverallScore(),
            'qualityLevel' => $scoreResult->getQualityLevel(),
            'suggestedMetaTitle' => $this->seoSuggestionService->generateMetaTitleSuggestion($product),
            'suggestedMetaDescription' => $this->seoSuggestionService->generateMetaDescriptionSuggestion($product),
        ];
    }

    private function getMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.minProductDescriptionLength'
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