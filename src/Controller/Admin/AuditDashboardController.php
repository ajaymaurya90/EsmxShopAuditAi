<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Controller\Admin;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use EsmxShopAuditAi\Service\Audit\ProductAuditService;
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

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class AuditDashboardController extends AbstractController
{
    public function __construct(
        private readonly ProductAuditService $productAuditService,
        private readonly ManualScanRunner $manualScanRunner,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository
    ) {
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/dashboard',
        name: 'api.action.esmx-shop-audit-ai.dashboard',
        methods: ['GET']
    )]
    public function loadDashboard(Context $context): JsonResponse
    {
        return new JsonResponse(
            $this->productAuditService->buildDashboardSummary($context)
        );
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
            $data[] = [
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
                'payloadJson' => $task->getPayloadJson(),
            ];
        }

        return new JsonResponse([
            'scan' => $this->serializeScan($scan),
            'tasks' => $data,
        ]);
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
}