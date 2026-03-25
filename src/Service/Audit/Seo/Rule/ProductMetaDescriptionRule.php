<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoAuditDataProvider;
use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use EsmxShopAuditAi\Service\Audit\Seo\SeoSuggestionService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductMetaDescriptionRule extends AbstractScoredProductSeoAuditRule
{
    private const int DEFAULT_MIN_LENGTH = 80;
    private const int DEFAULT_MAX_LENGTH = 160;
    private const int DEFAULT_WEAK_SCORE_THRESHOLD = 70;

    public function __construct(
        SystemConfigService $systemConfigService,
        ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly SeoSuggestionService $seoSuggestionService
    ) {
        parent::__construct($systemConfigService, $productSeoAuditDataProvider);
    }

    public function getCode(): string
    {
        return 'product_meta_description';
    }

    public function getTitle(): string
    {
        return 'Products with meta description issues';
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
            'EsmxShopAuditAi.config.checkProductMetaDescription'
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
        $maxLength = $this->getMaxLength();

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $scoreResult = $this->getScoreResult($product, $scoreResults);

            if ($scoreResult === null) {
                continue;
            }

            $translated = $product->getTranslated();
            $metaTitle = trim((string) ($translated['metaTitle'] ?? ''));
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

            $reason = $this->resolveReason($metaDescription, $scoreResult, $minLength, $maxLength);

            if ($reason === null) {
                continue;
            }

            if ($this->shouldSkipInEffectiveMode($product, 'metaDescription', $seen)) {
                continue;
            }

            $suggestion = $this->seoSuggestionService->suggestForMissingMetaDescription($product, $scoreResult);

            $result[] = $this->buildSeoIssuePayload($product, 'metaDescription', $scoreResult->getMetaDescriptionScore(), [
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'metaDescriptionLength' => mb_strlen($metaDescription),
                'metaDescriptionScore' => $scoreResult->getMetaDescriptionScore(),
                'overallSeoScore' => $scoreResult->getOverallScore(),
                'qualityLevel' => $scoreResult->getQualityLevel(),
                'reason' => $reason,
                'suggestion' => array_merge($suggestion, [
                    'reason' => $reason,
                ]),
            ]);
        }

        return $result;
    }

    private function resolveReason(
        string $metaDescription,
        ProductSeoScoreResult $scoreResult,
        int $minLength,
        int $maxLength
    ): ?string {
        if ($metaDescription === '') {
            return 'Meta description is missing';
        }

        $length = mb_strlen($metaDescription);

        if ($length < $minLength) {
            return 'Meta description is too short';
        }

        if ($length > $maxLength) {
            return 'Meta description is too long';
        }

        if ($scoreResult->getMetaDescriptionScore() < self::DEFAULT_WEAK_SCORE_THRESHOLD) {
            return 'Meta description needs SEO improvement';
        }

        return null;
    }

    private function getMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaDescriptionMinLength'
        ) ?? self::DEFAULT_MIN_LENGTH);
    }

    private function getMaxLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaDescriptionMaxLength'
        ) ?? self::DEFAULT_MAX_LENGTH);
    }
}