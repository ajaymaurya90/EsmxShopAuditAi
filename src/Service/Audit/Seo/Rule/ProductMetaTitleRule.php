<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoAuditDataProvider;
use EsmxShopAuditAi\Service\Audit\Seo\ProductSeoScoreResult;
use EsmxShopAuditAi\Service\Audit\Seo\SeoSuggestionService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductMetaTitleRule extends AbstractScoredProductSeoAuditRule
{
    private const DEFAULT_MIN_LENGTH = 30;
    private const DEFAULT_MAX_LENGTH = 65;
    private const DEFAULT_WEAK_SCORE_THRESHOLD = 70;

    public function __construct(
        SystemConfigService $systemConfigService,
        ProductSeoAuditDataProvider $productSeoAuditDataProvider,
        private readonly SeoSuggestionService $seoSuggestionService
    ) {
        parent::__construct($systemConfigService, $productSeoAuditDataProvider);
    }

    public function getCode(): string
    {
        return 'product_meta_title';
    }

    public function getTitle(): string
    {
        return 'Products with meta title issues';
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
            'EsmxShopAuditAi.config.checkProductMetaTitle'
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
            $productName = trim((string) ($translated['name'] ?? ''));
            $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

            $reason = $this->resolveReason(
                $metaTitle,
                $productName,
                $scoreResult,
                $minLength,
                $maxLength
            );

            if ($reason === null) {
                continue;
            }

            if ($this->shouldSkipInEffectiveMode($product, 'metaTitle', $seen)) {
                continue;
            }

            $suggestion = $this->seoSuggestionService->suggestForWeakMetaTitle($product, $scoreResult);

            $result[] = $this->buildSeoIssuePayload($product, 'metaTitle', $scoreResult->getMetaTitleScore(), [
                'metaTitle' => $metaTitle,
                'metaTitleLength' => mb_strlen($metaTitle),
                'metaDescription' => $metaDescription,
                'metaTitleScore' => $scoreResult->getMetaTitleScore(),
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
        string $metaTitle,
        string $productName,
        ProductSeoScoreResult $scoreResult,
        int $minLength,
        int $maxLength
    ): ?string {
        if ($metaTitle === '') {
            return 'Meta title is missing';
        }

        if ($productName !== '' && mb_strtolower($metaTitle) === mb_strtolower($productName)) {
            return 'Meta title is identical to the product name';
        }

        $length = mb_strlen($metaTitle);

        if ($length < $minLength) {
            return 'Meta title is too short';
        }

        if ($length > $maxLength) {
            return 'Meta title is too long';
        }

        if ($scoreResult->getMetaTitleScore() < self::DEFAULT_WEAK_SCORE_THRESHOLD) {
            return 'Meta title needs SEO improvement';
        }

        return null;
    }

    private function getMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaTitleMinLength'
        ) ?? self::DEFAULT_MIN_LENGTH);
    }

    private function getMaxLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaTitleMaxLength'
        ) ?? self::DEFAULT_MAX_LENGTH);
    }
}