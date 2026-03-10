<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

use EsmxShopAuditAi\Service\Audit\ProductAuditService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use EsmxShopAuditAi\Service\Audit\Seo\SeoAuditService;

class ManualScanRunner
{
    public function __construct(
        private readonly ProductAuditService $productAuditService,
        private readonly SeoAuditService $seoAuditService,
        private readonly FindingBuilder $findingBuilder,
        private readonly TaskBuilder $taskBuilder,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository
    ) {
    }

    public function run(Context $context): string
    {
        $scanId = Uuid::randomHex();
        $startedAt = new \DateTimeImmutable();

        $this->scanRepository->create([
            [
                'id' => $scanId,
                'status' => 'running',
                'startedAt' => $startedAt,
                'scannedProducts' => 0,
                'totalFindings' => 0,
                'highPriorityFindings' => 0,
                'summaryJson' => null,
            ],
        ], $context);

        try {
            $auditSummary = $this->productAuditService->buildDashboardSummary($context);
            $seoIssues = $this->seoAuditService->run($context);
            foreach ($seoIssues as $code => $definition) {
                $auditSummary['issues'][$code] = $definition['items'];
                $auditSummary['totals'][$code] = count($definition['items']);
            }

            //Recalculate total issues after merging SEO checks
            $auditSummary['totals']['totalIssues'] = 0;

            foreach ($auditSummary['totals'] as $key => $value) {
                if ($key === 'totalIssues') {
                    continue;
                }

                $auditSummary['totals']['totalIssues'] += (int) $value;
            }

            $findings = $this->findingBuilder->build($scanId, $auditSummary);
            $tasks = $this->taskBuilder->build($scanId, $findings);

            if ($findings !== []) {
                $this->findingRepository->create($findings, $context);
            }

            if ($tasks !== []) {
                $this->taskRepository->create($tasks, $context);
            }

            $highPriorityFindings = $this->countHighPriorityFindings($findings);

            $this->scanRepository->update([
                [
                    'id' => $scanId,
                    'status' => 'completed',
                    'finishedAt' => new \DateTimeImmutable(),
                    'scannedProducts' => (int) ($auditSummary['meta']['scannedProducts'] ?? 0),
                    'totalFindings' => \count($findings),
                    'highPriorityFindings' => $highPriorityFindings,
                    'summaryJson' => [
                        'meta' => $auditSummary['meta'] ?? [],
                        'totals' => $auditSummary['totals'] ?? [],
                        'findingCount' => \count($findings),
                        'taskCount' => \count($tasks),
                    ],
                ],
            ], $context);

            return $scanId;
        } catch (\Throwable $exception) {
            $this->scanRepository->update([
                [
                    'id' => $scanId,
                    'status' => 'failed',
                    'finishedAt' => new \DateTimeImmutable(),
                    'summaryJson' => [
                        'error' => $exception->getMessage(),
                    ],
                ],
            ], $context);

            throw $exception;
        }
    }

    private function countHighPriorityFindings(array $findings): int
    {
        $count = 0;

        foreach ($findings as $finding) {
            $severity = $finding['severity'] ?? null;

            if (\in_array($severity, ['high', 'critical'], true)) {
                $count++;
            }
        }

        return $count;
    }
}