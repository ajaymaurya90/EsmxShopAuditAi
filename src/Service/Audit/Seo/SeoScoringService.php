<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SeoScoringService
{
    private const int DEFAULT_META_TITLE_MIN_LENGTH = 30;
    private const int DEFAULT_META_TITLE_MAX_LENGTH = 65;
    private const int DEFAULT_META_DESCRIPTION_MIN_LENGTH = 80;
    private const int DEFAULT_META_DESCRIPTION_MAX_LENGTH = 160;
    private const int DEFAULT_DESCRIPTION_MIN_LENGTH = 120;
    private const int DEFAULT_IMPROVEMENT_THRESHOLD = 70;

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * @return array<string, ProductSeoScoreResult> Indexed by product ID
     */
    public function scoreProducts(ProductCollection $products): array
    {
        $results = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $productId = $product->getId();

            if ($productId === null) {
                continue;
            }

            $results[$productId] = $this->scoreProduct($product);
        }

        return $results;
    }

    public function scoreProduct(ProductEntity $product): ProductSeoScoreResult
    {
        $metaTitleScore = $this->calculateMetaTitleScore($product);
        $metaDescriptionScore = $this->calculateMetaDescriptionScore($product);
        $descriptionScore = $this->calculateDescriptionScore($product);

        $overallScore = $this->calculateOverallScore(
            $metaTitleScore,
            $metaDescriptionScore,
            $descriptionScore
        );

        return new ProductSeoScoreResult(
            productId: (string) $product->getId(),
            metaTitleScore: $metaTitleScore,
            metaDescriptionScore: $metaDescriptionScore,
            descriptionScore: $descriptionScore,
            overallScore: $overallScore,
            qualityLevel: $this->classifyQualityLevel($overallScore),
            penalties: []
        );
    }

    /**
     * @param array<string, ProductSeoScoreResult> $scoreResults
     */
    public function buildKpiResult(array $scoreResults): SeoAuditKpiResult
    {
        $totalProducts = \count($scoreResults);
        $improvementThreshold = $this->getImprovementThreshold();

        if ($totalProducts === 0) {
            return new SeoAuditKpiResult(
                totalProducts: 0,
                productsNeedingImprovement: 0,
                averageOverallScore: 0,
                improvementThreshold: $improvementThreshold
            );
        }

        $productsNeedingImprovement = 0;
        $scoreSum = 0;

        foreach ($scoreResults as $scoreResult) {
            $overallScore = $scoreResult->getOverallScore();
            $scoreSum += $overallScore;

            if ($overallScore < $improvementThreshold) {
                $productsNeedingImprovement++;
            }
        }

        $averageOverallScore = (int) round($scoreSum / $totalProducts);

        return new SeoAuditKpiResult(
            totalProducts: $totalProducts,
            productsNeedingImprovement: $productsNeedingImprovement,
            averageOverallScore: $averageOverallScore,
            improvementThreshold: $improvementThreshold
        );
    }

    private function calculateMetaTitleScore(ProductEntity $product): int
    {
        $translated = $product->getTranslated();
        $metaTitle = trim((string) ($translated['metaTitle'] ?? ''));
        $productName = trim((string) ($translated['name'] ?? ''));

        if ($metaTitle === '') {
            return 0;
        }

        $score = 100;
        $length = mb_strlen($metaTitle);
        $minLength = $this->getMetaTitleMinLength();
        $maxLength = $this->getMetaTitleMaxLength();

        if ($length < $minLength) {
            $score -= 35;
        }

        if ($length > $maxLength) {
            $score -= 20;
        }

        if ($productName !== '' && mb_strtolower($metaTitle) === mb_strtolower($productName)) {
            $score -= 15;
        }

        return $this->clampScore($score);
    }

    private function calculateMetaDescriptionScore(ProductEntity $product): int
    {
        $translated = $product->getTranslated();
        $metaDescription = trim((string) ($translated['metaDescription'] ?? ''));

        if ($metaDescription === '') {
            return 0;
        }

        $score = 100;
        $length = mb_strlen($metaDescription);
        $minLength = $this->getMetaDescriptionMinLength();
        $maxLength = $this->getMetaDescriptionMaxLength();

        if ($length < $minLength) {
            $score -= 30;
        }

        if ($length > $maxLength) {
            $score -= 20;
        }

        return $this->clampScore($score);
    }

    private function calculateDescriptionScore(ProductEntity $product): int
    {
        $translated = $product->getTranslated();
        $description = trim(strip_tags((string) ($translated['description'] ?? '')));

        if ($description === '') {
            return 0;
        }

        $score = 100;
        $length = mb_strlen($description);
        $minLength = $this->getDescriptionMinLength();

        if ($length < $minLength) {
            $score -= 35;
        }

        return $this->clampScore($score);
    }

    private function calculateOverallScore(
        int $metaTitleScore,
        int $metaDescriptionScore,
        int $descriptionScore
    ): int {
        $weightedScore =
            ($metaTitleScore * 0.40) +
            ($metaDescriptionScore * 0.35) +
            ($descriptionScore * 0.25);

        return $this->clampScore((int) round($weightedScore));
    }

    private function classifyQualityLevel(int $overallScore): string
    {
        if ($overallScore >= 80) {
            return 'good';
        }

        if ($overallScore >= 60) {
            return 'needs_improvement';
        }

        return 'poor';
    }

    private function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    private function getMetaTitleMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaTitleMinLength'
        ) ?? self::DEFAULT_META_TITLE_MIN_LENGTH);
    }

    private function getMetaTitleMaxLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaTitleMaxLength'
        ) ?? self::DEFAULT_META_TITLE_MAX_LENGTH);
    }

    private function getMetaDescriptionMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaDescriptionMinLength'
        ) ?? self::DEFAULT_META_DESCRIPTION_MIN_LENGTH);
    }

    private function getMetaDescriptionMaxLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoMetaDescriptionMaxLength'
        ) ?? self::DEFAULT_META_DESCRIPTION_MAX_LENGTH);
    }

    private function getDescriptionMinLength(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoDescriptionMinLength'
        ) ?? self::DEFAULT_DESCRIPTION_MIN_LENGTH);
    }

    private function getImprovementThreshold(): int
    {
        return (int) ($this->systemConfigService->get(
            'EsmxShopAuditAi.config.seoImprovementThreshold'
        ) ?? self::DEFAULT_IMPROVEMENT_THRESHOLD);
    }
}