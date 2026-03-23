<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

final class SeoAuditKpiResult
{
    public function __construct(
        private readonly int $totalProducts,
        private readonly int $productsNeedingImprovement,
        private readonly int $averageOverallScore,
        private readonly int $improvementThreshold
    ) {
    }

    public function getTotalProducts(): int
    {
        return $this->totalProducts;
    }

    public function getProductsNeedingImprovement(): int
    {
        return $this->productsNeedingImprovement;
    }

    public function getAverageOverallScore(): int
    {
        return $this->averageOverallScore;
    }

    public function getImprovementThreshold(): int
    {
        return $this->improvementThreshold;
    }

    public function getImprovementRate(): float
    {
        if ($this->totalProducts === 0) {
            return 0.0;
        }

        return round(($this->productsNeedingImprovement / $this->totalProducts) * 100, 2);
    }

    public function toArray(): array
    {
        return [
            'totalProducts' => $this->totalProducts,
            'productsNeedingImprovement' => $this->productsNeedingImprovement,
            'averageOverallScore' => $this->averageOverallScore,
            'improvementThreshold' => $this->improvementThreshold,
            'improvementRate' => $this->getImprovementRate(),
        ];
    }
}