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
        $liveAudit = $this->productAuditService->buildDashboardSummary($context);

        $seoIssues = $this->seoAuditService->run($context);

        foreach ($seoIssues as $code => $definition) {
            $liveAudit['issues'][$code] = $definition['items'];
            $liveAudit['totals'][$code] = count($definition['items']);
        }

        $liveAudit['totals']['totalIssues'] = 0;

        foreach ($liveAudit['totals'] as $key => $value) {
            if ($key === 'totalIssues') {
                continue;
            }

            $liveAudit['totals']['totalIssues'] += (int) $value;
        }

        $productIssueKeys = [
            'missingDescription',
            'missingCoverImage',
            'inactiveProducts',
            'outOfStockProducts',
            'missingMetaTitle',
            'missingCategory',
            'missingManufacturer',
            'missingPrice',
            'missingTranslation',
            'product_missing_meta_description',
            'product_weak_title',
            'product_short_description',
        ];

        $affectedProducts = [];

        foreach ($liveAudit['issues'] as $issueCode => $issueItems) {
            if (!in_array($issueCode, $productIssueKeys, true)) {
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
            'salesInsights' => $salesInsights
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
        $data = [];

        /** @var FindingEntity $finding */
        foreach ($findings as $finding) {
            $payload = $finding->getPayloadJson() ?? [];
            $items = [];

            if (\is_array($payload)) {
                if (isset($payload['items']) && \is_array($payload['items'])) {
                    $items = $payload['items'];
                } elseif (array_is_list($payload)) {
                    $items = $payload;
                }
            }

            $data[] = [
                'id' => $finding->getId(),
                'scanId' => $finding->getScanId(),
                'code' => $finding->getCode(),
                'title' => $finding->getTitle(),
                'severity' => $finding->getSeverity(),
                'entity' => $finding->getEntity(),
                'affectedCount' => $finding->getAffectedCount(),
                'items' => $items,
                'payloadJson' => $payload,
            ];
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
            $data[] = [
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
            ];
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
                $findings[] = [
                    'id' => $finding->getId(),
                    'scanId' => $finding->getScanId(),
                    'code' => $finding->getCode(),
                    'title' => $finding->getTitle(),
                    'severity' => $finding->getSeverity(),
                    'entity' => $finding->getEntity(),
                    'affectedCount' => $finding->getAffectedCount(),
                    'payloadJson' => $finding->getPayloadJson(),
                ];
            }
        }

        if ($scan->getTasks() !== null) {
            /** @var TaskEntity $task */
            foreach ($scan->getTasks() as $task) {
                $tasks[] = [
                    'id' => $task->getId(),
                    'scanId' => $task->getScanId(),
                    'code' => $task->getCode(),
                    'title' => $task->getTitle(),
                    'priority' => $task->getPriority(),
                    'affectedCount' => $task->getAffectedCount(),
                    'status' => $task->getStatus(),
                    'payloadJson' => $task->getPayloadJson(),
                    'impactScore' => $this->calculateTaskImpactScore(
                        (string) $task->getCode(),
                        (int) $task->getAffectedCount()
                    ),
                    'autoFixSupported' => $this->isAutoFixSupported($task),
                ];
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

        return new JsonResponse([
            'task' => [
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
                'payloadJson' => $taskPayload,
                'autoFixSupported' => $this->isAutoFixSupported($task),
            ],
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
                'issue' => $this->resolveTaskItemIssue($item, $task),
                'currentValue' => $this->resolveTaskItemCurrentValue($item),
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

        return (string) ($task->getTitle() ?? 'Issue detected');
    }

    private function resolveTaskItemCurrentValue(array $item): string
    {
        $candidates = [
            'currentValue',
            'metaTitle',
            'metaDescription',
            'description',
            'value',
        ];

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
        return match ($task->getCode()) {
            'add_meta_titles',
            'add_meta_descriptions' => true,
            default => false,
        };
    }

    private function calculateTaskImpactScore(string $taskCode, int $affectedCount): int
    {
        $weights = [
            'add_meta_titles' => 2.0,
            'add_meta_descriptions' => 2.0,
            'add_product_descriptions' => 1.5,
            'upload_product_images' => 1.0,
            'review_inactive_products' => 1.5,
            'review_out_of_stock_products' => 2.0,
            'assign_product_categories' => 1.5,
            'assign_product_manufacturers' => 1.0,
            'add_product_prices' => 3.0,
            'complete_product_translations' => 1.5,
        ];

        $weight = $weights[$taskCode] ?? 1.0;

        return (int) round($affectedCount * $weight);
    }
}