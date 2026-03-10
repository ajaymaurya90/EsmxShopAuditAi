<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Insights\Sales;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class SalesInsightService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderLineItemRepository,
        private readonly EntityRepository $productRepository
    ) {}

    public function getInsights(Context $context): array
    {
        return [
            'kpis' => $this->calculateKpis($context),
            'topProducts' => $this->getTopSellingProducts($context),
            'lowStockBestSellers' => $this->getLowStockBestSellers($context),
        ];
    }

    private function calculateKpis(Context $context): array
    {
        $now = new \DateTimeImmutable();
        $last30 = $now->modify('-30 days');
        $prev30 = $now->modify('-60 days');

        $current = $this->calculatePeriod($context, $last30, $now);
        $previous = $this->calculatePeriod($context, $prev30, $last30);

        return [
            'revenue' => $current['revenue'],
            'orders' => $current['orders'],
            'revenueChange' => $this->percentageChange($previous['revenue'], $current['revenue']),
            'ordersChange' => $this->percentageChange($previous['orders'], $current['orders']),
        ];
    }

    private function calculatePeriod(Context $context, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new RangeFilter('orderDateTime', [
                RangeFilter::GTE => $from->format('Y-m-d H:i:s'),
                RangeFilter::LTE => $to->format('Y-m-d H:i:s'),
            ])
        );

        $criteria->addAggregation(new SumAggregation('revenue', 'amountTotal'));
        $criteria->addAggregation(new CountAggregation('orders', 'id'));

        $result = $this->orderRepository->search($criteria, $context);

        $revenue = $result->getAggregations()->get('revenue')?->getSum() ?? 0;
        $orders = $result->getAggregations()->get('orders')?->getCount() ?? 0;

        return [
            'revenue' => (float) $revenue,
            'orders' => (int) $orders,
        ];
    }

    private function percentageChange(float $previous, float $current): float
    {
        if ($previous === 0.0) {
            return 0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function getTopSellingProducts(Context $context): array
    {
        $criteria = new Criteria();

        $criteria->addAggregation(
            new TermsAggregation(
                'top_products',
                'productId',
                5,
                null,
                null
            )
        );

        $result = $this->orderLineItemRepository->search($criteria, $context);

        $buckets = $result->getAggregations()->get('top_products')?->getBuckets() ?? [];

        $products = [];

        foreach ($buckets as $bucket) {
            $products[] = [
                'productId' => $bucket->getKey(),
                'sales' => $bucket->getCount(),
            ];
        }

        return $products;
    }

    private function getLowStockBestSellers(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->setLimit(5);

        $criteria->addFilter(
            new RangeFilter('stock', [
                RangeFilter::LTE => 5
            ])
        );

        $criteria->addSorting(new FieldSorting('stock', FieldSorting::ASCENDING));

        $products = $this->productRepository->search($criteria, $context);

        $result = [];

        foreach ($products->getEntities() as $product) {
            $translated = $product->getTranslated();

            $result[] = [
                'id' => $product->getId(),
                'name' => $translated['name'] ?? $product->getProductNumber(),
                'stock' => $product->getStock(),
            ];
        }

        return $result;
    }
}