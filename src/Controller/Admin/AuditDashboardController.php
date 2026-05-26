<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Controller\Admin;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use EsmxShopAuditAi\Service\Insights\Sales\SalesInsightService;
use EsmxShopAuditAi\Service\Scan\ScanCapabilitiesResolver;
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
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class AuditDashboardController extends AbstractController
{
    private const TASK_IMPACT_WEIGHTS = [
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
        'recover_abandoned_carts' => 2.5,
    ];

    private const BROKEN_LINK_SOURCE_LABELS = [
        'product_description' => 'Product description',
        'category_description' => 'Category description',
        'cms_content' => 'CMS page content',
    ];

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

    private const SCAN_OPTION_HEALTH_CODES = [
        'productHealth' => [
            'missingCoverImage' => 'missingCoverImage',
            'inactiveProducts' => 'inactiveProducts',
            'outOfStockProducts' => 'outOfStockProducts',
            'missingCategory' => 'missingCategory',
            'missingManufacturer' => 'missingManufacturer',
            'missingPrice' => 'missingPrice',
            'missingTranslation' => 'missingTranslation',
        ],
        'seo' => [
            'product_name' => 'product_name',
            'product_description' => 'product_description',
            'product_meta_title' => 'product_meta_title',
            'product_meta_description' => 'product_meta_description',
            'category_missing_meta_title' => 'category_missing_meta_title',
            'category_missing_meta_description' => 'category_missing_meta_description',
            'category_missing_description' => 'category_missing_description',
        ],
        'brokenLinks' => [
            'product_description' => 'broken_links',
            'category_description' => 'broken_links',
            'cms_content' => 'broken_links',
        ],
        'customerAudit' => [
            'abandonedCartCustomers' => 'abandoned_cart_customers',
        ],
    ];

    public function __construct(
        private readonly ManualScanRunner $manualScanRunner,
        private readonly SalesInsightService $salesInsightService,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository,
        private readonly TaskAutoFixService $taskAutoFixService,
        private readonly ScanCapabilitiesResolver $scanCapabilitiesResolver,
    ) {
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/dashboard',
        name: 'api.action.esmx-shop-audit-ai.dashboard',
        methods: ['GET']
    )]
    public function loadDashboard(Context $context): JsonResponse
    {
        $latestScan = $this->getLatestScanEntity($context);
        $salesInsights = $this->salesInsightService->getInsights($context);
        $scanCapabilities = $this->scanCapabilitiesResolver->resolve();

        if ($latestScan === null) {
            $emptyAudit = $this->buildEmptyDashboardAudit();

            return new JsonResponse([
                'liveAudit' => $emptyAudit,
                'scanAudit' => $emptyAudit,
                'latestScan' => null,
                'insights' => [
                    'openTaskCount' => 0,
                    'topTasks' => [],
                    'topFindings' => [],
                    'latestSummary' => null,
                    'affectedProducts' => 0,
                    'criticalIssues' => 0,
                    'highIssues' => 0,
                ],
                'salesInsights' => $salesInsights,
                'health' => $this->calculateHealthScore($emptyAudit['totals'], 0, null),
                'scanCapabilities' => $scanCapabilities,
            ]);
        }

        $summaryJson = \is_array($latestScan->getSummaryJson()) ? $latestScan->getSummaryJson() : [];
        $scanAudit = [
            'meta' => \is_array($summaryJson['meta'] ?? null) ? $summaryJson['meta'] : [],
            'totals' => \is_array($summaryJson['totals'] ?? null) ? $summaryJson['totals'] : [],
            'entityStats' => \is_array($summaryJson['entityStats'] ?? null) ? $summaryJson['entityStats'] : $this->buildEmptyEntityStats(),
            'customerStats' => \is_array($summaryJson['customerStats'] ?? null) ? $summaryJson['customerStats'] : $this->buildEmptyCustomerStats(),
            'issues' => [],
            'scanOptions' => \is_array($summaryJson['scanOptions'] ?? null) ? $summaryJson['scanOptions'] : null,
        ];

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
        $highIssues = 0;

        foreach ($allFindings as $finding) {
            /** @var FindingEntity $finding */
            $payload = $finding->getPayloadJson() ?? [];
            $items = $this->extractPayloadItems($payload);

            $scanAudit['issues'][(string) $finding->getCode()] = $items;

            if ($finding->getSeverity() === 'critical') {
                $criticalIssues++;
            }

            if ($finding->getSeverity() === 'high') {
                $highIssues++;
            }

            if (\in_array($finding->getSeverity(), ['high', 'critical'], true)) {
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
        }

        $openTaskCountCriteria = new Criteria();
        $openTaskCountCriteria->addFilter(new EqualsFilter('scanId', $latestScan->getId()));
        $openTaskCountCriteria->addFilter(new EqualsFilter('status', 'open'));

        $openTaskCount = $this->taskRepository->search($openTaskCountCriteria, $context)->getTotal();

        $latestSummary = [
            'scanId' => $latestScan->getId(),
            'status' => $latestScan->getStatus(),
            'scannedProducts' => $latestScan->getScannedProducts(),
            'totalFindings' => $latestScan->getTotalFindings(),
            'highPriorityFindings' => $latestScan->getHighPriorityFindings(),
            'taskCount' => $summaryJson['taskCount'] ?? 0,
            'findingCount' => $summaryJson['findingCount'] ?? 0,
            'meta' => $scanAudit['meta'],
            'totals' => $scanAudit['totals'],
            'entityStats' => $scanAudit['entityStats'],
            'customerStats' => $scanAudit['customerStats'],
            'scanOptions' => $scanAudit['scanOptions'],
        ];

        $health = $this->calculateHealthScore(
            $scanAudit['totals'],
            $criticalIssues,
            $scanAudit['scanOptions']
        );

        return new JsonResponse([
            'liveAudit' => $scanAudit,
            'scanAudit' => $scanAudit,
            'latestScan' => $this->serializeScan($latestScan),
            'aiExecutiveSummary' => $this->extractAiExecutiveSummary($latestScan),
            'insights' => [
                'openTaskCount' => $openTaskCount,
                'topTasks' => $topTasks,
                'topFindings' => $topFindings,
                'latestSummary' => $latestSummary,
                'affectedProducts' => (int) ($scanAudit['entityStats']['products']['affected'] ?? 0),
                'criticalIssues' => $criticalIssues,
                'highIssues' => $highIssues,
            ],
            'salesInsights' => $salesInsights,
            'health' => $health,
            'scanCapabilities' => $scanCapabilities,
        ]);
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/run-scan',
        name: 'api.action.esmx-shop-audit-ai.run-scan',
        methods: ['POST']
    )]
    public function runScan(Context $context, Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $scanOptions = \is_array($payload) && \is_array($payload['scanOptions'] ?? null)
            ? $payload['scanOptions']
            : null;

        $scanId = $this->manualScanRunner->run($context, $scanOptions);

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
        path: '/api/_action/esmx-shop-audit-ai/reports/delete',
        name: 'api.action.esmx-shop-audit-ai.reports.delete',
        methods: ['POST']
    )]
    public function deleteReports(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!\is_array($payload)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid request payload.',
            ], 400);
        }

        $reportIds = $payload['reportIds'] ?? [];

        if (!\is_array($reportIds) || $reportIds === []) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No report ids provided.',
            ], 400);
        }

        $reportIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => \is_string($id) ? trim($id) : '',
            $reportIds
        ))));

        if ($reportIds === []) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No valid report ids provided.',
            ], 400);
        }

        $scanCriteria = new Criteria($reportIds);

        /** @var ScanEntity[] $scans */
        $scans = $this->scanRepository->search($scanCriteria, $context)->getEntities()->getElements();

        if ($scans === []) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No matching reports found.',
            ], 404);
        }

        $scanIds = [];
        foreach ($scans as $scan) {
            $scanId = $scan->getId();

            if ($scanId) {
                $scanIds[] = $scanId;
            }
        }

        if ($scanIds === []) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No matching report ids found.',
            ], 404);
        }

        $findingIds = $this->collectEntityIdsByScanIds($this->findingRepository, $scanIds, $context);
        $taskIds = $this->collectEntityIdsByScanIds($this->taskRepository, $scanIds, $context);

        if ($findingIds !== []) {
            $this->findingRepository->delete(
                array_map(static fn (string $id) => ['id' => $id], $findingIds),
                $context
            );
        }

        if ($taskIds !== []) {
            $this->taskRepository->delete(
                array_map(static fn (string $id) => ['id' => $id], $taskIds),
                $context
            );
        }

        $this->scanRepository->delete(
            array_map(static fn (string $id) => ['id' => $id], $scanIds),
            $context
        );

        return new JsonResponse([
            'success' => true,
            'deletedCount' => \count($scanIds),
            'deletedScanIds' => $scanIds,
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

    private function buildEmptyDashboardAudit(): array
    {
        return [
            'meta' => [
                'scannedProducts' => 0,
                'seo' => [
                    'totalProducts' => 0,
                    'productsNeedingImprovement' => 0,
                    'averageOverallScore' => 0,
                    'improvementThreshold' => 0,
                    'improvementRate' => 0.0,
                ],
            ],
            'totals' => [
                'totalIssues' => 0,
            ],
            'issues' => [],
            'entityStats' => $this->buildEmptyEntityStats(),
            'customerStats' => $this->buildEmptyCustomerStats(),
            'scanOptions' => null,
        ];
    }

    private function buildEmptyEntityStats(): array
    {
        return [
            'products' => [
                'affected' => 0,
                'scanned' => 0,
            ],
            'categories' => [
                'affected' => 0,
                'scanned' => 0,
            ],
            'cmsPages' => [
                'affected' => 0,
                'scanned' => 0,
            ],
        ];
    }

    private function buildEmptyCustomerStats(): array
    {
        return [
            'abandonedCarts' => [
                'affected' => 0,
                'potentialRevenue' => 0.0,
                'customersScanned' => 0,
                'cartsScanned' => 0,
            ],
        ];
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

    private function extractAiExecutiveSummary(ScanEntity $scan): ?array
    {
        $summaryJson = \is_array($scan->getSummaryJson()) ? $scan->getSummaryJson() : [];
        $summary = \is_array($summaryJson['ai']['executiveSummary'] ?? null)
            ? $summaryJson['ai']['executiveSummary']
            : null;

        if ($summary === null) {
            return null;
        }

        $text = trim((string) ($summary['summary'] ?? ''));

        if ($text === '') {
            return null;
        }

        if (!$this->isCompleteAiSummary($text)) {
            return null;
        }

        return [
            'success' => true,
            'summary' => $text,
            'provider' => \is_string($summary['provider'] ?? null) ? $summary['provider'] : '',
            'model' => \is_string($summary['model'] ?? null) ? $summary['model'] : '',
            'scanId' => $scan->getId(),
            'generatedAt' => \is_string($summary['generatedAt'] ?? null) ? $summary['generatedAt'] : '',
            'cached' => true,
        ];
    }

    private function isCompleteAiSummary(string $summary): bool
    {
        $summary = trim($summary);

        if ($summary === '') {
            return false;
        }

        if (!preg_match('/[.!?)]$/u', $summary)) {
            return false;
        }

        $words = preg_split('/\s+/u', mb_strtolower($summary));

        if (!\is_array($words) || $words === []) {
            return false;
        }

        $lastWord = trim((string) end($words), " \t\n\r\0\x0B.,!?;:()[]{}\"'");

        return !\in_array($lastWord, ['a', 'an', 'the', 'with', 'and', 'or', 'but', 'for', 'to', 'of', 'in', 'on', 'at', 'by'], true);
    }

    // Normalizes mixed finding payload items into a consistent task detail grid format.
    private function normalizeTaskDetailItems(array $rawItems, TaskEntity $task): array
    {
        $normalized = [];
        $isBrokenLinkTask = $this->isBrokenLinkTask($task);

        foreach ($rawItems as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            if ($isBrokenLinkTask) {
                $entityId = $item['id'] ?? null;

                $normalized[] = [
                    'id' => sprintf('broken-link-%s-%s', (string) ($entityId ?? 'item'), $index),
                    'entityId' => $entityId,
                    'sourceEntityId' => $entityId,
                    'entityType' => $this->resolveTaskEntityType($task, $item),
                    'entity' => (string) ($item['entity'] ?? 'link'),
                    'name' => $this->resolveTaskItemName($item),
                    'languageName' => (string) ($item['languageName'] ?? '-'),
                    'source' => (string) ($item['source'] ?? '-'),
                    'status' => $item['status'] ?? '-',
                    'url' => (string) ($item['url'] ?? '-'),
                    'error' => (string) ($item['error'] ?? '-'),
                    'raw' => $item,
                    'autoFixSupported' => false,
                ];

                continue;
            }

            if ($task->getCode() === 'recover_abandoned_carts') {
                $normalized[] = [
                    'id' => (string) ($item['id'] ?? $index),
                    'entityId' => $item['customerId'] ?? null,
                    'entityType' => 'customer',
                    'entity' => 'customer',
                    'name' => (string) ($item['customerName'] ?? 'Unnamed customer'),
                    'email' => (string) ($item['email'] ?? '-'),
                    'cartValue' => (float) ($item['cartValue'] ?? 0),
                    'productCount' => (int) ($item['productCount'] ?? 0),
                    'lastActivityAt' => (string) ($item['lastActivityAt'] ?? ''),
                    'salesChannelName' => (string) ($item['salesChannelName'] ?? '-'),
                    'cartToken' => (string) ($item['cartToken'] ?? ''),
                    'raw' => $item,
                    'autoFixSupported' => false,
                ];

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

    private function isBrokenLinkTask(TaskEntity $task): bool
    {
        $payload = $task->getPayloadJson() ?? [];

        return $task->getCode() === 'fix_broken_links'
            || ($payload['findingCode'] ?? null) === 'broken_links';
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
        if (!empty($item['url']) && !empty($item['source'])) {
            return sprintf(
                '%s: %s',
                self::BROKEN_LINK_SOURCE_LABELS[(string) $item['source']] ?? $this->formatReadableIdentifier((string) $item['source']),
                (string) $item['url']
            );
        }

        if (!empty($item['url'])) {
            return (string) $item['url'];
        }

        $candidates = [
            'productNumber',
            'identifier',
            'number',
        ];

        foreach ($candidates as $key) {
            if (!empty($item[$key])) {
                return (string) $item[$key];
            }
        }

        return '-';
    }

    private function formatReadableIdentifier(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
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
    private function calculateHealthScore(array $totals, int $criticalIssues, ?array $scanOptions): array
    {
        $includedHealthCodes = $this->resolveIncludedHealthCodes($scanOptions);

        if ($includedHealthCodes === []) {
            return [
                'score' => null,
                'status' => 'not_available',
                'label' => 'No audit checks included',
                'breakdown' => [],
                'includedChecks' => [],
            ];
        }

        $score = 100;
        $breakdown = [];

        foreach (self::HEALTH_SCORE_RULES as $key => $rule) {
            if (!\in_array($key, $includedHealthCodes, true)) {
                continue;
            }

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
            'status' => 'available',
            'label' => null,
            'breakdown' => $breakdown,
            'includedChecks' => $includedHealthCodes,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveIncludedHealthCodes(?array $scanOptions): array
    {
        if ($scanOptions === null) {
            return array_keys(self::HEALTH_SCORE_RULES);
        }

        $included = [];

        foreach (self::SCAN_OPTION_HEALTH_CODES as $groupKey => $checks) {
            $group = $scanOptions[$groupKey] ?? null;

            if (!\is_array($group) || ($group['enabled'] ?? true) === false) {
                continue;
            }

            $groupChecks = $group['checks'] ?? [];

            if (!\is_array($groupChecks)) {
                continue;
            }

            foreach ($checks as $checkKey => $healthCode) {
                if (($groupChecks[$checkKey] ?? false) === true) {
                    $included[$healthCode] = true;
                }
            }
        }

        return array_values(array_keys($included));
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

    private function collectEntityIdsByScanIds(
        EntityRepository $repository,
        array $scanIds,
        Context $context
    ): array {
        if ($scanIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('scanId', $scanIds));

        $entities = $repository->search($criteria, $context)->getEntities();

        $ids = [];

        foreach ($entities as $entity) {
            $id = $entity->getUniqueIdentifier();

            if (\is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
