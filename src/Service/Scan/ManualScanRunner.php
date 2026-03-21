<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

use EsmxShopAuditAi\Service\Audit\ProductAuditService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use EsmxShopAuditAi\Service\Audit\Seo\SeoAuditService;
use Psr\Log\LoggerInterface;

class ManualScanRunner
{
    public function __construct(
        private readonly ProductAuditService $productAuditService,
        private readonly SeoAuditService $seoAuditService,
        private readonly FindingBuilder $findingBuilder,
        private readonly TaskBuilder $taskBuilder,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $taskRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(Context $context): string
    {
        $auditSummary = [];
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

        $this->logger->info('EsmxShopAuditAi scan started', [
            'scanId' => $scanId,
            'startedAt' => $startedAt->format(DATE_ATOM),
        ]);

        try {
            $auditSummary = $this->productAuditService->buildProductAuditSummary($context);
            $seoIssues = $this->seoAuditService->run($context);
            $auditSummary = $this->productAuditService->mergeIssuesIntoSummary($auditSummary, $seoIssues);

            $findings = $this->findingBuilder->build($scanId, $auditSummary);
            $tasks = $this->taskBuilder->build($scanId, $findings);
            $highPriorityFindings = $this->countHighPriorityFindings($findings);
            $finishedAt = new \DateTimeImmutable();

            if ($findings !== []) {
                $this->findingRepository->create($findings, $context);
            }

            if ($tasks !== []) {
                $this->taskRepository->create($tasks, $context);
            }

            $this->scanRepository->update([
                [
                    'id' => $scanId,
                    'status' => 'completed',
                    'finishedAt' => $finishedAt,
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

            $this->logger->info('EsmxShopAuditAi scan completed', [
                'scanId' => $scanId,
                'finishedAt' => $finishedAt->format(DATE_ATOM),
                'scannedProducts' => (int) ($auditSummary['meta']['scannedProducts'] ?? 0),
                'findingCount' => \count($findings),
                'taskCount' => \count($tasks),
                'highPriorityFindings' => $highPriorityFindings,
            ]);

            return $scanId;
        } catch (\Throwable $exception) {
            $finishedAt = new \DateTimeImmutable();

            $this->scanRepository->update([
                [
                    'id' => $scanId,
                    'status' => 'failed',
                    'finishedAt' => $finishedAt,
                    'summaryJson' => [
                        'meta' => $auditSummary['meta'] ?? [],
                        'error' => $exception->getMessage(),
                    ],
                ],
            ], $context);

            $this->logger->error('EsmxShopAuditAi scan failed', [
                'scanId' => $scanId,
                'finishedAt' => $finishedAt->format(DATE_ATOM),
                'meta' => $auditSummary['meta'] ?? [],
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    // Counts high and critical findings for persisted scan summary reporting.
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