<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

final class ProductSeoScoreResult
{
    /**
     * @param array<int, array<string, mixed>> $penalties
     */
    public function __construct(
        private readonly string $productId,
        private readonly int $metaTitleScore,
        private readonly int $metaDescriptionScore,
        private readonly int $descriptionScore,
        private readonly int $overallScore,
        private readonly string $qualityLevel,
        private readonly array $penalties = []
    ) {
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getMetaTitleScore(): int
    {
        return $this->metaTitleScore;
    }

    public function getMetaDescriptionScore(): int
    {
        return $this->metaDescriptionScore;
    }

    public function getDescriptionScore(): int
    {
        return $this->descriptionScore;
    }

    public function getOverallScore(): int
    {
        return $this->overallScore;
    }

    public function getQualityLevel(): string
    {
        return $this->qualityLevel;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPenalties(): array
    {
        return $this->penalties;
    }

    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'metaTitleScore' => $this->metaTitleScore,
            'metaDescriptionScore' => $this->metaDescriptionScore,
            'descriptionScore' => $this->descriptionScore,
            'overallScore' => $this->overallScore,
            'qualityLevel' => $this->qualityLevel,
            'penalties' => $this->penalties,
        ];
    }
}