<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCompressor;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Psr\Log\LoggerInterface;

class AbandonedCartAuditService
{
    private const CANDIDATE_LIMIT = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly CartCompressor $cartCompressor,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly LoggerInterface $logger,
        private readonly string $cartStorageType
    ) {
    }

    public function run(Context $context, int $thresholdMinutes, float $minimumValue): array
    {
        $empty = $this->buildResult([], 0, 0.0, 0, 0);

        if ($this->cartStorageType !== 'mysql') {
            $this->logger->info('EsmxShopAuditAi customer audit skipped because cart storage is not MySQL', [
                'cartStorageType' => $this->cartStorageType,
            ]);

            return $empty;
        }

        if (!$this->hasRequiredTables()) {
            $this->logger->warning('EsmxShopAuditAi customer audit skipped because cart persistence tables are unavailable');

            return $empty;
        }

        $thresholdMinutes = max(1, $thresholdMinutes);
        $minimumValue = max(0.0, $minimumValue);
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $thresholdMinutes));

        $rows = $this->loadCandidateRows($threshold);
        $cartsScanned = \count($rows);

        if ($rows === []) {
            return $empty;
        }

        $customerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => isset($row['customer_id_hex']) ? (string) $row['customer_id_hex'] : null,
            $rows
        ))));
        $salesChannelIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => isset($row['sales_channel_id_hex']) ? (string) $row['sales_channel_id_hex'] : null,
            $rows
        ))));

        $customers = $this->loadCustomers($customerIds, $context);
        $salesChannels = $this->loadSalesChannels($salesChannelIds, $context);

        $items = [];
        $potentialRevenue = 0.0;
        $scannedCustomers = [];

        foreach ($rows as $row) {
            $customerId = (string) ($row['customer_id_hex'] ?? '');
            $token = (string) ($row['token'] ?? '');
            $lastActivity = $this->parseDateTime($row['cart_activity_at'] ?? null);

            if ($customerId === '' || $token === '' || $lastActivity === null) {
                continue;
            }

            $scannedCustomers[$customerId] = true;

            if ($this->hasCompletedOrderAfter($customerId, $lastActivity)) {
                continue;
            }

            $cart = $this->deserializeCart($row);

            if (!$cart instanceof Cart || $cart->getLineItems()->count() <= 0) {
                continue;
            }

            $cartValue = (float) $cart->getPrice()->getTotalPrice();

            if ($cartValue < $minimumValue) {
                continue;
            }

            $customer = $customers[$customerId] ?? null;
            $salesChannelId = (string) ($row['sales_channel_id_hex'] ?? '');
            $salesChannel = $salesChannels[$salesChannelId] ?? null;

            $items[] = [
                'id' => sha1($customerId . '|' . $token),
                'entity' => 'customer',
                'customerId' => $customerId,
                'customerName' => $this->formatCustomerName($customer, $customerId),
                'email' => $customer?->getEmail() ?? '',
                'cartToken' => $token,
                'cartValue' => round($cartValue, 2),
                'productCount' => $cart->getLineItems()->count(),
                'lastActivityAt' => $lastActivity->format(DATE_ATOM),
                'salesChannelId' => $salesChannelId,
                'salesChannelName' => $salesChannel?->getTranslation('name') ?? $salesChannel?->getName() ?? '',
            ];

            $potentialRevenue += $cartValue;
        }

        return $this->buildResult($items, \count($scannedCustomers), $potentialRevenue, $cartsScanned, \count($items));
    }

    private function hasRequiredTables(): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist(['cart', 'sales_channel_api_context']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadCandidateRows(\DateTimeImmutable $threshold): array
    {
        $sql = <<<'SQL'
            SELECT
                c.token,
                c.payload,
                c.compressed,
                c.created_at AS cart_activity_at,
                LOWER(HEX(sac.customer_id)) AS customer_id_hex,
                LOWER(HEX(sac.sales_channel_id)) AS sales_channel_id_hex
            FROM cart c
            INNER JOIN sales_channel_api_context sac ON sac.token = c.token
            WHERE sac.customer_id IS NOT NULL
              AND c.payload IS NOT NULL
              AND c.created_at <= :threshold
            ORDER BY c.created_at DESC
            LIMIT :limit
        SQL;

        try {
            $statement = $this->connection->prepare($sql);
            $statement->bindValue('threshold', $threshold->format('Y-m-d H:i:s'));
            $statement->bindValue('limit', self::CANDIDATE_LIMIT, ParameterType::INTEGER);

            return $statement->executeQuery()->fetchAllAssociative();
        } catch (Exception $exception) {
            $this->logger->warning('EsmxShopAuditAi customer audit candidate query failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, CustomerEntity>
     */
    private function loadCustomers(array $customerIds, Context $context): array
    {
        if ($customerIds === []) {
            return [];
        }

        $criteria = new Criteria($customerIds);

        /** @var CustomerCollection $collection */
        $collection = $this->customerRepository->search($criteria, $context)->getEntities();
        $customers = [];

        foreach ($collection as $customer) {
            $customers[$customer->getId()] = $customer;
        }

        return $customers;
    }

    /**
     * @return array<string, SalesChannelEntity>
     */
    private function loadSalesChannels(array $salesChannelIds, Context $context): array
    {
        if ($salesChannelIds === []) {
            return [];
        }

        $criteria = new Criteria($salesChannelIds);
        $criteria->addAssociation('translations');

        /** @var SalesChannelCollection $collection */
        $collection = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $salesChannels = [];

        foreach ($collection as $salesChannel) {
            $salesChannels[$salesChannel->getId()] = $salesChannel;
        }

        return $salesChannels;
    }

    private function hasCompletedOrderAfter(string $customerId, \DateTimeImmutable $lastActivity): bool
    {
        try {
            $completedOrders = (int) $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(o.id)
                    FROM `order` o
                    INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
                    INNER JOIN state_machine_state sms ON sms.id = o.state_id
                    WHERE oc.customer_id = :customerId
                      AND o.order_date_time >= :lastActivity
                      AND sms.technical_name = 'completed'
                    LIMIT 1
                SQL,
                [
                    'customerId' => Uuid::fromHexToBytes($customerId),
                    'lastActivity' => $lastActivity->format('Y-m-d H:i:s'),
                ]
            );

            return $completedOrders > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function deserializeCart(array $row): ?Cart
    {
        try {
            $payload = $row['payload'] ?? null;

            if (\is_resource($payload)) {
                $payload = stream_get_contents($payload);
            }

            if (!\is_string($payload) || $payload === '') {
                return null;
            }

            $cart = $this->cartCompressor->unserialize($payload, (int) ($row['compressed'] ?? 0));

            return $cart instanceof Cart ? $cart : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatCustomerName(?CustomerEntity $customer, string $fallback): string
    {
        if ($customer === null) {
            return $fallback;
        }

        $name = trim(sprintf('%s %s', (string) $customer->getFirstName(), (string) $customer->getLastName()));

        return $name !== '' ? $name : ($customer->getEmail() ?? $fallback);
    }

    private function buildResult(array $items, int $customersScanned, float $potentialRevenue, int $cartsScanned, int $abandonedCartCount): array
    {
        return [
            'abandoned_cart_customers' => $items,
            'stats' => [
                'customersScanned' => $customersScanned,
                'cartsScanned' => $cartsScanned,
                'abandonedCartCount' => $abandonedCartCount,
                'potentialRevenue' => round($potentialRevenue, 2),
            ],
        ];
    }
}
