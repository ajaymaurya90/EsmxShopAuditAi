<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Controller\Admin;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use EsmxShopAuditAi\Service\Audit\ProductAuditService;
use EsmxShopAuditAi\Service\Audit\Seo\SeoAuditService;
use EsmxShopAuditAi\Service\Insights\Sales\SalesInsightService;
use EsmxShopAuditAi\Service\Scan\ManualScanRunner;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use EsmxShopAuditAi\Service\Task\TaskAutoFixService;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class AuditDashboardController extends AbstractController
{
    private const array PRODUCT_ISSUE_KEYS = [
        'missingCoverImage',
        'inactiveProducts',
        'outOfStockProducts',
        'missingCategory',
        'missingManufacturer',
        'missingPrice',
        'missingTranslation',
        'product_name',
        'product_description',
        'product_meta_title',
        'product_meta_description',
    ];

    private const array TASK_IMPACT_WEIGHTS = [
        'review_product_names' => 2.0,
        'review_product_descriptions' => 2.0,
        'review_product_meta_titles' => 2.0,
        'review_product_meta_descriptions' => 2.0,
        'upload_product_images' => 1.0,
        'review_inactive_products' => 1.5,
        'review_out_of_stock_products' => 2.0,
        'assign_product_categories' => 1.5,
        'assign_product_manufacturers' => 1.0,
        'add_product_prices' => 3.0,
        'complete_product_translations' => 1.5,
    ];

    private const array HEALTH_SCORE_RULES = [
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
    ];

    public function __construct(
        private readonly ProductAuditService $productAuditService,
        private readonly ManualScanRunner $manualScanRunner,
        private readonly SalesInsightService $salesInsightService,
        private readonly SeoAuditService $seoAuditService,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository,
        private readonly TaskAutoFixService $taskAutoFixService,
    ) {
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/dashboard',
        name: 'api.action.esmx-shop-audit-ai.dashboard',
        methods: ['GET']
    )]
    public function loadDashboard(Context $context): JsonResponse
    {
        $liveAudit = $this->productAuditService->buildProductAuditSummary($context);
        $seoAuditResult = $this->seoAuditService->run($context);
        $liveAudit = $this->productAuditService->mergeSeoAuditResultIntoSummary($liveAudit, $seoAuditResult);

        $affectedProducts = [];

        foreach ($liveAudit['issues'] as $issueCode => $issueItems) {
            if (!in_array($issueCode, self::PRODUCT_ISSUE_KEYS, true)) {
                continue;
            }

            foreach ($issueItems as $item) {
                if (!empty($item['id'])) {
                    $affectedProducts[$item['id']] = true;
                }
            }
        }

        $affectedProductsCount = \count($affectedProducts);

        $latestScan = $this->getLatestScanEntity($context);

        if ($latestScan === null) {
            return new JsonResponse([
                'liveAudit' => $liveAudit,
                'latestScan' => null,
                'insights' => [
                    'openTaskCount' => 0,
                    'topTasks' => [],
                    'topFindings' => [],
                    'latestSummary' => null,
                    'affectedProducts' => 0,
                    'criticalIssues' => 0,
                ],
                'salesInsights' => [
                    'kpis' => [
                        'revenue' => 0,
                        'orders' => 0,
                        'revenueChange' => 0,
                        'ordersChange' => 0,
                    ],
                    'topProducts' => [],
                    'lowStockBestSellers' => [],
                ],
            ]);
        }

        $taskCriteria = new Criteria();
        $taskCriteria->addFilter(new EqualsFilter('scanId', $latestScan->getId()));
        $taskCriteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));
        $taskCriteria->setLimit(3);

        $topTasks = [];
        foreach ($this->taskRepository->search($taskCriteria, $context)->getEntities() as $task) {
            /** @var TaskEntity $task */
            $topTasks[] = [
                'id' => $task->getId(),
                'code' => $task->getCode(),
                'title' => $task->getTitle(),
                'priority' => $task->getPriority(),
                'affectedCount' => $task->getAffectedCount(),
                'status' => $task->getStatus(),
            ];
        }

        $findingCriteria = new Criteria();
        $findingCriteria->addFilter(new EqualsFilter('scanId', $latestScan->getId()));
        $findingCriteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));

        $allFindings = $this->findingRepository->search($findingCriteria, $context)->getEntities();

        $topFindings = [];
        $criticalIssues = 0;

        foreach ($allFindings as $finding) {
            /** @var FindingEntity $finding */
            if (!\in_array($finding->getSeverity(), ['high', 'critical'], true)) {
                continue;
            }

            $criticalIssues++;

            if (\count($topFindings) < 3) {
                $topFindings[] = [
                    'id' => $finding->getId(),
                    'code' => $finding->getCode(),
                    'title' => $finding->getTitle(),
                    'severity' => $finding->getSeverity(),
                    'affectedCount' => $finding->getAffectedCount(),
                ];
            }
        }

        $openTaskCountCriteria = new Criteria();
        $openTaskCountCriteria->addFilter(new EqualsFilter('scanId', $latestScan->getId()));
        $openTaskCountCriteria->addFilter(new EqualsFilter('status', 'open'));

        $openTaskCount = $this->taskRepository->search($openTaskCountCriteria, $context)->getTotal();

        $summaryJson = $latestScan->getSummaryJson() ?? [];
        $latestSummary = [
            'scanId' => $latestScan->getId(),
            'status' => $latestScan->getStatus(),
            'scannedProducts' => $latestScan->getScannedProducts(),
            'totalFindings' => $latestScan->getTotalFindings(),
            'highPriorityFindings' => $latestScan->getHighPriorityFindings(),
            'taskCount' => $summaryJson['taskCount'] ?? 0,
            'findingCount' => $summaryJson['findingCount'] ?? 0,
        ];

        $salesInsights = $this->salesInsightService->getInsights($context);

        $health = $this->calculateHealthScore(
            $liveAudit['totals'],
            $criticalIssues
        );

        return new JsonResponse([
            'liveAudit' => $liveAudit,
            'latestScan' => $this->serializeScan($latestScan),
            'insights' => [
                'openTaskCount' => $openTaskCount,
                'topTasks' => $topTasks,
                'topFindings' => $topFindings,
                'latestSummary' => $latestSummary,
                'affectedProducts' => $affectedProductsCount,
                'criticalIssues' => $criticalIssues,
            ],
            'salesInsights' => $salesInsights,
            'health' => $health,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/run-scan',
        name: 'api.action.esmx-shop-audit-ai.run-scan',
        methods: ['POST']
    )]
    public function runScan(Context $context): JsonResponse
    {
        $scanId = $this->manualScanRunner->run($context);

        return new JsonResponse([
            'success' => true,
            'scanId' => $scanId,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/latest-scan',
        name: 'api.action.esmx-shop-audit-ai.latest-scan',
        methods: ['GET']
    )]
    public function loadLatestScan(Context $context): JsonResponse
    {
        $scan = $this->getLatestScanEntity($context);

        if ($scan === null) {
            return new JsonResponse([
                'scan' => null,
            ]);
        }

        return new JsonResponse([
            'scan' => $this->serializeScan($scan),
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/latest-findings',
        name: 'api.action.esmx-shop-audit-ai.latest-findings',
        methods: ['GET']
    )]
    public function loadLatestFindings(Context $context): JsonResponse
    {
        $scan = $this->getLatestScanEntity($context);

        if ($scan === null) {
            return new JsonResponse([
                'scan' => null,
                'findings' => [],
            ]);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $scan->getId()));
        $criteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));

        $findings = $this->findingRepository->search($criteria, $context)->getEntities();

        $data = [];

        /** @var FindingEntity $finding */
        foreach ($findings as $finding) {
            $data[] = $this->serializeFinding($finding);
        }

        return new JsonResponse([
            'scan' => $this->serializeScan($scan),
            'findings' => $data,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/latest-tasks',
        name: 'api.action.esmx-shop-audit-ai.latest-tasks',
        methods: ['GET']
    )]
    public function loadLatestTasks(Context $context): JsonResponse
    {
        $scan = $this->getLatestScanEntity($context);

        if ($scan === null) {
            return new JsonResponse([
                'scan' => null,
                'tasks' => [],
            ]);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $scan->getId()));
        $criteria->addSorting(new FieldSorting('affectedCount', FieldSorting::DESCENDING));

        $tasks = $this->taskRepository->search($criteria, $context)->getEntities();

        $data = [];

        /** @var TaskEntity $task */
        foreach ($tasks as $task) {
            $data[] = $this->serializeTask($task);
        }

        return new JsonResponse([
            'scan' => $this->serializeScan($scan),
            'tasks' => $data,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/reports',
        name: 'api.action.esmx-shop-audit-ai.reports',
        methods: ['GET']
    )]
    public function loadReports(Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $scans = $this->scanRepository->search($criteria, $context)->getEntities();

        $data = [];

        /** @var ScanEntity $scan */
        foreach ($scans as $scan) {
            $data[] = $this->serializeScan($scan);
        }

        return new JsonResponse([
            'reports' => $data,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/report-detail/{id}',
        name: 'api.action.esmx-shop-audit-ai.report-detail',
        methods: ['GET']
    )]
    public function loadReportDetail(string $id, Context $context): JsonResponse
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('findings');
        $criteria->addAssociation('tasks');

        /** @var ?ScanEntity $scan */
        $scan = $this->scanRepository->search($criteria, $context)->first();

        if ($scan === null) {
            return new JsonResponse([
                'report' => null,
                'findings' => [],
                'tasks' => [],
            ]);
        }

        $findings = [];
        $tasks = [];

        if ($scan->getFindings() !== null) {
            /** @var FindingEntity $finding */
            foreach ($scan->getFindings() as $finding) {
                $findings[] = $this->serializeFinding($finding);
            }
        }

        if ($scan->getTasks() !== null) {
            /** @var TaskEntity $task */
            foreach ($scan->getTasks() as $task) {
                $tasks[] = $this->serializeTask($task);
            }
        }

        return new JsonResponse([
            'report' => $this->serializeScan($scan),
            'findings' => $findings,
            'tasks' => $tasks,
        ]);
    }


    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/task-detail/{taskId}',
        name: 'api.action.esmx-shop-audit-ai.task-detail',
        methods: ['GET']
    )]
    public function loadTaskDetail(string $taskId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$taskId]);

        /** @var ?TaskEntity $task */
        $task = $this->taskRepository->search($criteria, $context)->first();

        if ($task === null) {
            return new JsonResponse([
                'task' => null,
                'items' => [],
            ], 404);
        }

        $taskPayload = $task->getPayloadJson() ?? [];
        $findingCode = $taskPayload['findingCode'] ?? null;

        $findingItems = [];

        if ($findingCode) {
            $findingCriteria = new Criteria();
            $findingCriteria->addFilter(new EqualsFilter('scanId', $task->getScanId()));
            $findingCriteria->addFilter(new EqualsFilter('code', $findingCode));

            /** @var ?FindingEntity $finding */
            $finding = $this->findingRepository->search($findingCriteria, $context)->first();

            if ($finding !== null) {
                $findingPayload = $finding->getPayloadJson() ?? [];

                if (isset($findingPayload['items']) && \is_array($findingPayload['items'])) {
                    $findingItems = $findingPayload['items'];
                } elseif (array_is_list($findingPayload)) {
                    $findingItems = $findingPayload;
                }
            }
        }

        $items = $this->normalizeTaskDetailItems($findingItems, $task);
        $taskData = $this->serializeTask($task);

        return new JsonResponse([
            'task' => $taskData,
            'items' => $items,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/task-auto-fix-preview/{taskId}/{itemId}',
        name: 'api.action.esmx-shop-audit-ai.task-auto-fix-preview',
        methods: ['GET']
    )]
    public function loadTaskAutoFixPreview(string $taskId, string $itemId, Context $context): JsonResponse
    {
        $preview = $this->taskAutoFixService->getPreview($taskId, $itemId, $context);

        return new JsonResponse($preview);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/task-auto-fix-apply/{taskId}/{itemId}',
        name: 'api.action.esmx-shop-audit-ai.task-auto-fix-apply',
        methods: ['POST']
    )]
    public function applyTaskAutoFix(string $taskId, string $itemId, Context $context): JsonResponse
    {
        $result = $this->taskAutoFixService->apply($taskId, $itemId, $context);

        return new JsonResponse($result);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/task-auto-fix-apply-all/{taskId}',
        name: 'api.action.esmx-shop-audit-ai.task-auto-fix-apply-all',
        methods: ['POST']
    )]
    public function applyTaskAutoFixAll(string $taskId, Context $context): JsonResponse
    {
        $result = $this->taskAutoFixService->applyAll($taskId, $context);

        return new JsonResponse($result);
    }


    // Serializes a finding entity for dashboard/report/detail API responses.
    private function serializeFinding(FindingEntity $finding): array
    {
        $payload = $finding->getPayloadJson() ?? [];

        return [
            'id' => $finding->getId(),
            'scanId' => $finding->getScanId(),
            'code' => $finding->getCode(),
            'title' => $finding->getTitle(),
            'severity' => $finding->getSeverity(),
            'entity' => $finding->getEntity(),
            'affectedCount' => $finding->getAffectedCount(),
            'items' => $this->extractPayloadItems($payload),
            'payloadJson' => $payload,
        ];
    }

    // Extracts normalized item arrays from finding payloads that may be wrapped or flat.
    private function extractPayloadItems(array $payload): array
    {
        if (isset($payload['items']) && \is_array($payload['items'])) {
            return $payload['items'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    // Serializes a task entity with computed impact and auto-fix metadata.
    private function serializeTask(TaskEntity $task): array
    {
        return [
            'id' => $task->getId(),
            'scanId' => $task->getScanId(),
            'code' => $task->getCode(),
            'title' => $task->getTitle(),
            'priority' => $task->getPriority(),
            'affectedCount' => $task->getAffectedCount(),
            'status' => $task->getStatus(),
            'impactScore' => $this->calculateTaskImpactScore(
                (string) $task->getCode(),
                (int) $task->getAffectedCount()
            ),
            'payloadJson' => $task->getPayloadJson(),
            'autoFixSupported' => $this->isAutoFixSupported($task),
        ];
    }

    private function getLatestScanEntity(Context $context): ?ScanEntity
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var ?ScanEntity $scan */
        $scan = $this->scanRepository->search($criteria, $context)->first();

        return $scan;
    }

    private function serializeScan(ScanEntity $scan): array
    {
        return [
            'id' => $scan->getId(),
            'status' => $scan->getStatus(),
            'startedAt' => $scan->getStartedAt()?->format(DATE_ATOM),
            'finishedAt' => $scan->getFinishedAt()?->format(DATE_ATOM),
            'scannedProducts' => $scan->getScannedProducts(),
            'totalFindings' => $scan->getTotalFindings(),
            'highPriorityFindings' => $scan->getHighPriorityFindings(),
            'summaryJson' => $scan->getSummaryJson(),
        ];
    }

    // Normalizes mixed finding payload items into a consistent task detail grid format.
    private function normalizeTaskDetailItems(array $rawItems, TaskEntity $task): array
    {
        $normalized = [];

        foreach ($rawItems as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($item['id'] ?? $index),
                'entityId' => $item['id'] ?? null,
                'entityType' => $this->resolveTaskEntityType($task, $item),
                'name' => $this->resolveTaskItemName($item),
                'identifier' => $this->resolveTaskItemIdentifier($item),
                'fieldType' => $this->resolveTaskFieldType($task),
                'issue' => $this->resolveTaskItemIssue($item, $task),
                'reason' => $this->resolveTaskItemIssue($item, $task),
                'currentValue' => $this->resolveTaskItemCurrentValue($item, $task),
                'seoScore' => $this->resolveTaskItemSeoScore($item),
                'raw' => $item,
                'autoFixSupported' => $this->isAutoFixSupported($task),
            ];
        }

        return $normalized;
    }

    private function resolveTaskEntityType(TaskEntity $task, array $item): string
    {
        if (!empty($item['entity'])) {
            return (string) $item['entity'];
        }

        if (!empty($item['entityType'])) {
            return (string) $item['entityType'];
        }

        $code = $task->getCode() ?? '';

        if (str_contains($code, 'category')) {
            return 'category';
        }

        return 'product';
    }

    private function resolveTaskItemName(array $item): string
    {
        $candidates = [
            'name',
            'productName',
            'categoryName',
            'title',
            'label',
        ];

        foreach ($candidates as $key) {
            if (!empty($item[$key])) {
                return (string) $item[$key];
            }
        }

        return 'Unnamed item';
    }

    private function resolveTaskItemIdentifier(array $item): string
    {
        $candidates = [
            'productNumber',
            'identifier',
            'number',
            'id',
        ];

        foreach ($candidates as $key) {
            if (!empty($item[$key])) {
                return (string) $item[$key];
            }
        }

        return '-';
    }

    private function resolveTaskItemIssue(array $item, TaskEntity $task): string
    {
        $candidates = [
            'issue',
            'reason',
            'message',
        ];

        foreach ($candidates as $key) {
            if (!empty($item[$key])) {
                return (string) $item[$key];
            }
        }

        return match ((string) $task->getCode()) {
            'review_product_names' => 'Needs improvement',
            'review_product_descriptions' => 'Needs improvement',
            'review_product_meta_titles' => 'Needs improvement',
            'review_product_meta_descriptions' => 'Needs improvement',
            default => (string) ($task->getTitle() ?? 'Issue detected'),
        };
    }

    private function resolveTaskItemCurrentValue(array $item, TaskEntity $task): string
    {
        $taskCode = (string) $task->getCode();

        $candidates = match ($taskCode) {
            'review_product_names' => [
                'currentValue',
                'name',
                'productName',
                'value',
            ],
            'review_product_descriptions' => [
                'currentValue',
                'description',
                'productDescription',
                'value',
            ],
            'review_product_meta_titles' => [
                'currentValue',
                'metaTitle',
                'value',
            ],
            'review_product_meta_descriptions' => [
                'currentValue',
                'metaDescription',
                'value',
            ],
            default => [
                'currentValue',
                'metaTitle',
                'metaDescription',
                'description',
                'name',
                'value',
            ],
        };

        foreach ($candidates as $key) {
            if (array_key_exists($key, $item)) {
                $value = $item[$key];

                if ($value === null || $value === '') {
                    return '-';
                }

                if (\is_scalar($value)) {
                    return (string) $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
            }
        }

        return '-';
    }

    private function isAutoFixSupported(TaskEntity $task): bool
    {
        return match ((string) $task->getCode()) {
            'review_product_names',
            'review_product_descriptions',
            'review_product_meta_titles',
            'review_product_meta_descriptions' => true,
            default => false,
        };
    }

    // Calculates a lightweight prioritization score used to sort tasks by expected business impact.
    private function calculateTaskImpactScore(string $taskCode, int $affectedCount): int
    {
        $weight = self::TASK_IMPACT_WEIGHTS[$taskCode] ?? 1.0;

        return (int) round($affectedCount * $weight);
    }

    // Builds the Store Health score and penalty breakdown used by the dashboard health widget.
    private function calculateHealthScore(array $totals, int $criticalIssues): array
    {
        $score = 100;
        $breakdown = [];

        foreach (self::HEALTH_SCORE_RULES as $key => $rule) {
            $count = (int) ($totals[$key] ?? 0);
            $penalty = min($count * $rule['weight'], $rule['max']);

            $score -= $penalty;

            $breakdown[] = [
                'key' => $key,
                'count' => $count,
                'penalty' => $penalty,
                'weight' => $rule['weight'],
            ];
        }

        // critical issues penalty
        $criticalPenalty = min($criticalIssues * 4, 20);
        $score -= $criticalPenalty;

        $breakdown[] = [
            'key' => 'criticalIssues',
            'count' => $criticalIssues,
            'penalty' => $criticalPenalty,
            'weight' => 4,
        ];

        return [
            'score' => max(0, (int) round($score)),
            'breakdown' => $breakdown,
        ];
    }

    private function resolveTaskFieldType(TaskEntity $task): string
    {
        return match ((string) $task->getCode()) {
            'review_product_names' => 'name',
            'review_product_descriptions' => 'description',
            'review_product_meta_titles' => 'metaTitle',
            'review_product_meta_descriptions' => 'metaDescription',
            default => 'generic',
        };
    }

    private function resolveTaskItemSeoScore(array $item): int
    {
        $candidates = [
            'seoScore',
            'score',
        ];

        foreach ($candidates as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (int) round((float) $item[$key]);
            }
        }

        return 0;
    }
}