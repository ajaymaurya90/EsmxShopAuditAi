<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class AiExecutiveSummaryService
{
    private const MIN_EXECUTIVE_SUMMARY_MAX_TOKENS = 800;

    private const HEALTH_SCORE_RULES = [
        'outOfStockProducts' => ['weight' => 3, 'max' => 30],
        'missingPrice' => ['weight' => 4, 'max' => 25],
        'inactiveProducts' => ['weight' => 3, 'max' => 20],
        'missingCoverImage' => ['weight' => 1, 'max' => 8],
        'missingCategory' => ['weight' => 2, 'max' => 12],
        'missingManufacturer' => ['weight' => 1, 'max' => 8],
        'missingTranslation' => ['weight' => 1, 'max' => 10],
        'product_name' => ['weight' => 1, 'max' => 10],
        'product_description' => ['weight' => 1, 'max' => 10],
        'product_meta_title' => ['weight' => 1, 'max' => 10],
        'product_meta_description' => ['weight' => 1, 'max' => 10],
        'category_missing_meta_title' => ['weight' => 1, 'max' => 8],
        'category_missing_meta_description' => ['weight' => 1, 'max' => 8],
        'category_missing_description' => ['weight' => 1, 'max' => 8],
        'broken_links' => ['weight' => 3, 'max' => 30],
        'abandoned_cart_customers' => ['weight' => 2, 'max' => 20],
    ];

    public function __construct(
        private readonly AiManagerService $aiManagerService,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function generate(?string $scanId, Context $context, bool $forceRegenerate = false): array
    {
        $scan = $scanId !== null && trim($scanId) !== ''
            ? $this->loadScanById(trim($scanId), $context)
            : $this->loadLatestCompletedScan($context);

        if (!$scan instanceof ScanEntity) {
            return [
                'success' => false,
                'message' => 'No completed scan found for AI summary generation.',
            ];
        }

        $existingSummary = $this->getPersistedExecutiveSummary($scan);

        if ($existingSummary !== null && !$forceRegenerate) {
            return [
                'success' => true,
                'summary' => $existingSummary['summary'],
                'provider' => $existingSummary['provider'],
                'model' => $existingSummary['model'],
                'scanId' => $scan->getId(),
                'generatedAt' => $existingSummary['generatedAt'],
                'cached' => true,
            ];
        }

        $payload = $this->buildSanitizedPayload($scan, $context);
        $prompt = $this->buildPrompt($payload);
        $generatedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
        $result = $this->aiManagerService->generateText(
            $prompt,
            'AI executive summary generated.',
            self::MIN_EXECUTIVE_SUMMARY_MAX_TOKENS
        );

        $this->logger->debug('AI executive summary AI manager result', [
            'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
            'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
            'success' => (bool) ($result['success'] ?? false),
            'generatedTextLength' => \is_string($result['text'] ?? null) ? mb_strlen((string) $result['text']) : 0,
        ]);

        if (($result['success'] ?? false) !== true) {
            $this->logger->warning('Gemini executive summary failed: ' . (string) ($result['message'] ?? 'AI summary failed.'), [
                'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
            ]);

            return [
                'success' => false,
                'message' => $this->sanitizeFailureMessage((string) ($result['message'] ?? 'AI summary failed.')),
                'scanId' => $scan->getId(),
                'generatedAt' => $generatedAt,
            ];
        }

        $summary = trim((string) ($result['text'] ?? ''));

        if ($summary === '') {
            $this->logger->warning('Gemini executive summary returned empty text', [
                'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
                'finalSummaryLength' => 0,
            ]);

            return [
                'success' => false,
                'message' => 'AI summary response was empty.',
                'scanId' => $scan->getId(),
                'generatedAt' => $generatedAt,
            ];
        }

        if (!$this->isCompleteSummary($summary)) {
            $this->logger->warning('AI executive summary returned incomplete text', [
                'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
                'finalSummaryLength' => mb_strlen($summary),
            ]);

            return [
                'success' => false,
                'message' => 'AI summary response was incomplete. Please retry or increase Max tokens.',
                'scanId' => $scan->getId(),
                'generatedAt' => $generatedAt,
            ];
        }

        $provider = (string) ($result['provider'] ?? '');
        $model = (string) ($result['model'] ?? '');

        try {
            $this->persistExecutiveSummary($scan, $summary, $provider, $model, $generatedAt, $context);
        } catch (AiProviderException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'scanId' => $scan->getId(),
                'generatedAt' => $generatedAt,
            ];
        }

        $this->logger->debug('AI executive summary final summary length returned to dashboard: ' . mb_strlen($summary), [
            'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
            'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
            'finalSummaryLength' => mb_strlen($summary),
        ]);

        return [
            'success' => true,
            'summary' => $summary,
            'provider' => $provider,
            'model' => $model,
            'scanId' => $scan->getId(),
            'generatedAt' => $generatedAt,
            'cached' => false,
        ];
    }

    private function getPersistedExecutiveSummary(ScanEntity $scan): ?array
    {
        $summaryJson = $this->normalizeSummaryJson($scan->getSummaryJson());
        $summary = $summaryJson['ai']['executiveSummary'] ?? null;

        if (!\is_array($summary)) {
            return null;
        }

        $text = trim((string) ($summary['summary'] ?? ''));

        if ($text === '') {
            return null;
        }

        if (!$this->isCompleteSummary($text)) {
            return null;
        }

        return [
            'summary' => $text,
            'provider' => \is_string($summary['provider'] ?? null) ? $summary['provider'] : '',
            'model' => \is_string($summary['model'] ?? null) ? $summary['model'] : '',
            'generatedAt' => \is_string($summary['generatedAt'] ?? null) ? $summary['generatedAt'] : '',
        ];
    }

    private function persistExecutiveSummary(ScanEntity $scan, string $summary, string $provider, string $model, string $generatedAt, Context $context): void
    {
        $summaryJson = $this->normalizeSummaryJson($scan->getSummaryJson());

        $this->logger->debug('AI executive summary summaryJson keys before update', [
            'scanId' => $scan->getId(),
            'summaryJsonKeys' => array_keys($summaryJson),
        ]);

        $this->assertRequiredSummaryJsonKeys($summaryJson, 'before');

        $ai = \is_array($summaryJson['ai'] ?? null) ? $summaryJson['ai'] : [];

        $ai['executiveSummary'] = [
            'summary' => $summary,
            'provider' => $provider,
            'model' => $model,
            'generatedAt' => $generatedAt,
        ];

        $summaryJson['ai'] = $ai;

        $this->logger->debug('AI executive summary summaryJson keys after update', [
            'scanId' => $scan->getId(),
            'summaryJsonKeys' => array_keys($summaryJson),
        ]);

        $this->assertRequiredSummaryJsonKeys($summaryJson, 'after');

        try {
            $encodedSummaryJson = json_encode($summaryJson, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            $this->logger->warning('AI executive summary persistence blocked because summaryJson could not be encoded', [
                'scanId' => $scan->getId(),
                'error' => $exception->getMessage(),
            ]);

            throw new AiProviderException('AI summary could not be saved because the scan summary data could not be encoded.');
        }

        $updatedRows = $this->connection->executeStatement(
            'UPDATE `esmx_shop_audit_scan`
             SET `summary_json` = :summaryJson,
                 `updated_at` = :updatedAt
             WHERE `id` = :id',
            [
                'summaryJson' => $encodedSummaryJson,
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'id' => Uuid::fromHexToBytes($scan->getId()),
            ]
        );

        if ($updatedRows < 1) {
            throw new AiProviderException('AI summary could not be saved because the scan was not found.');
        }
    }

    private function normalizeSummaryJson(mixed $summaryJson): array
    {
        if (\is_array($summaryJson)) {
            return $summaryJson;
        }

        if (\is_string($summaryJson) && trim($summaryJson) !== '') {
            $decoded = json_decode($summaryJson, true);

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function assertRequiredSummaryJsonKeys(array $summaryJson, string $stage): void
    {
        $requiredKeys = [
            'meta',
            'totals',
            'entityStats',
            'customerStats',
            'findingCount',
            'taskCount',
            'scanOptions',
            'scanCapabilities',
        ];

        $missingKeys = array_values(array_filter(
            $requiredKeys,
            static fn (string $key): bool => !array_key_exists($key, $summaryJson)
        ));

        if ($missingKeys === []) {
            return;
        }

        $this->logger->warning('AI executive summary persistence blocked because summaryJson is missing required keys', [
            'stage' => $stage,
            'missingKeys' => $missingKeys,
            'summaryJsonKeys' => array_keys($summaryJson),
        ]);

        throw new AiProviderException('AI summary could not be saved because the scan summary data is incomplete.');
    }

    private function loadScanById(string $scanId, Context $context): ?ScanEntity
    {
        $criteria = new Criteria([$scanId]);

        /** @var ?ScanEntity $scan */
        $scan = $this->scanRepository->search($criteria, $context)->first();

        if (!$scan instanceof ScanEntity || $scan->getStatus() !== 'completed') {
            return null;
        }

        return $scan;
    }

    private function loadLatestCompletedScan(Context $context): ?ScanEntity
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('status', 'completed'));
        $criteria->addSorting(new FieldSorting('finishedAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var ?ScanEntity $scan */
        $scan = $this->scanRepository->search($criteria, $context)->first();

        return $scan;
    }

    private function buildSanitizedPayload(ScanEntity $scan, Context $context): array
    {
        $summaryJson = $this->normalizeSummaryJson($scan->getSummaryJson());
        $totals = $this->filterScalarMap($summaryJson['totals'] ?? []);
        $topFindings = $this->loadTopFindings($scan->getId(), $context);
        $criticalFindingCount = $this->countCriticalFindings($scan->getId(), $context);

        return [
            'scan' => [
                'id' => $scan->getId(),
                'status' => $scan->getStatus(),
                'finishedAt' => $scan->getFinishedAt()?->format(DATE_ATOM),
                'scannedProducts' => $scan->getScannedProducts(),
                'totalFindings' => $scan->getTotalFindings(),
                'highPriorityFindings' => $scan->getHighPriorityFindings(),
            ],
            'healthScore' => $this->calculateHealthScore($totals, $criticalFindingCount),
            'totals' => $totals,
            'entityStats' => $this->filterNestedScalarMap($summaryJson['entityStats'] ?? []),
            'customerStats' => $this->sanitizeCustomerStats($summaryJson['customerStats'] ?? []),
            'topFindings' => $topFindings,
            'topTasks' => $this->loadTopTasks($scan->getId(), $context),
            'scanOptions' => $this->summarizeScanOptions($summaryJson['scanOptions'] ?? []),
        ];
    }

    private function loadTopFindings(string $scanId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $scanId));
        $criteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));
        $criteria->setLimit(8);

        $findings = [];

        foreach ($this->findingRepository->search($criteria, $context)->getEntities() as $finding) {
            /** @var FindingEntity $finding */
            $findings[] = [
                'code' => $finding->getCode(),
                'title' => $finding->getTitle(),
                'severity' => $finding->getSeverity(),
                'affectedCount' => $finding->getAffectedCount(),
            ];
        }

        return $findings;
    }

    private function countCriticalFindings(string $scanId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $scanId));
        $criteria->addFilter(new EqualsFilter('severity', 'critical'));

        return $this->findingRepository->search($criteria, $context)->getTotal();
    }

    private function loadTopTasks(string $scanId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $scanId));
        $criteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));
        $criteria->setLimit(8);

        $tasks = [];

        foreach ($this->taskRepository->search($criteria, $context)->getEntities() as $task) {
            /** @var TaskEntity $task */
            $tasks[] = [
                'title' => $task->getTitle(),
                'priority' => $task->getPriority(),
                'affectedCount' => $task->getAffectedCount(),
            ];
        }

        return $tasks;
    }

    private function calculateHealthScore(array $totals, int $criticalFindingCount): int
    {
        if ($totals === [] && $criticalFindingCount === 0) {
            return 100;
        }

        $penalty = 0;

        foreach (self::HEALTH_SCORE_RULES as $key => $rule) {
            $count = (int) ($totals[$key] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $penalty += min((int) $rule['max'], $count * (int) $rule['weight']);
        }

        $penalty += min(35, $criticalFindingCount * 5);

        return max(0, 100 - $penalty);
    }

    private function summarizeScanOptions(mixed $scanOptions): array
    {
        if (!\is_array($scanOptions)) {
            return [];
        }

        $summary = [];

        foreach ($scanOptions as $groupName => $groupOptions) {
            if (!\is_array($groupOptions)) {
                continue;
            }

            $checks = \is_array($groupOptions['checks'] ?? null) ? $groupOptions['checks'] : [];

            $summary[(string) $groupName] = [
                'enabled' => (bool) ($groupOptions['enabled'] ?? true),
                'enabledChecks' => array_values(array_keys(array_filter(
                    $checks,
                    static fn ($enabled): bool => $enabled === true
                ))),
            ];
        }

        return $summary;
    }

    private function buildPrompt(array $payload): string
    {
        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (!\is_string($json)) {
            $json = '{}';
        }

        return <<<PROMPT
Generate a concise business-friendly executive summary for a Shopware store audit.

Use only the sanitized aggregate audit data below. Do not invent product, customer, order, cart, URL, or personal details.

Include:
- overall store health
- main risks
- top priorities
- quick wins
- abandoned cart/customer risk only when aggregate customerStats indicates it exists

Keep the output around 50-100 words. Use clear professional language.
Do not use markdown, headings, bullets, or tables. Return one short paragraph only.
End the summary with a complete sentence and final punctuation.

Sanitized audit data:
{$json}
PROMPT;
    }

    private function isCompleteSummary(string $summary): bool
    {
        $summary = trim($summary);

        if ($summary === '') {
            return false;
        }

        if (!preg_match('/[.!?)]$/u', $summary)) {
            return false;
        }

        $lastWords = preg_split('/\s+/u', mb_strtolower($summary));

        if (!\is_array($lastWords) || $lastWords === []) {
            return false;
        }

        $lastWord = trim((string) end($lastWords), " \t\n\r\0\x0B.,!?;:()[]{}\"'");

        return !\in_array($lastWord, ['a', 'an', 'the', 'with', 'and', 'or', 'but', 'for', 'to', 'of', 'in', 'on', 'at', 'by'], true);
    }

    private function filterScalarMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $filtered = [];

        foreach ($value as $key => $item) {
            if (\is_scalar($item) || $item === null) {
                $filtered[(string) $key] = $item;
            }
        }

        return $filtered;
    }

    private function filterNestedScalarMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $filtered = [];

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $filtered[(string) $key] = $this->filterScalarMap($item);
                continue;
            }

            if (\is_scalar($item) || $item === null) {
                $filtered[(string) $key] = $item;
            }
        }

        return $filtered;
    }

    private function sanitizeCustomerStats(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $abandonedCarts = \is_array($value['abandonedCarts'] ?? null) ? $value['abandonedCarts'] : [];
        $allowedKeys = ['affected', 'potentialRevenue', 'customersScanned', 'cartsScanned'];
        $safeAbandonedCarts = [];

        foreach ($allowedKeys as $key) {
            $item = $abandonedCarts[$key] ?? null;

            if (\is_scalar($item) || $item === null) {
                $safeAbandonedCarts[$key] = $item;
            }
        }

        return $safeAbandonedCarts === [] ? [] : ['abandonedCarts' => $safeAbandonedCarts];
    }

    private function sanitizeFailureMessage(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'AI summary failed.';
        }

        $message = preg_replace('/key=([^&\s]+)/i', 'key=[hidden]', $message) ?? $message;
        $message = preg_replace('/(api[-_ ]?key[\'":=\s]+)([^\s"\',]+)/i', '$1[hidden]', $message) ?? $message;
        $message = preg_replace('/(authorization[\'":=\s]+bearer\s+)([^\s"\',]+)/i', '$1[hidden]', $message) ?? $message;

        return $message;
    }
}
