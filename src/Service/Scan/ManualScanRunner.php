<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Scan;

use EsmxShopAuditAi\Service\Audit\ProductAuditService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use EsmxShopAuditAi\Service\Audit\Seo\SeoAuditService;
use Psr\Log\LoggerInterface;
use EsmxShopAuditAi\Service\Audit\BrokenLink\BrokenLinkAuditService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

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
        private readonly BrokenLinkAuditService $brokenLinkAuditService,
        private readonly SystemConfigService $systemConfigService,
        private readonly ScanOptionsResolver $scanOptionsResolver,
        private readonly ScanCapabilitiesResolver $scanCapabilitiesResolver
    ) {}

    public function run(Context $context, ?array $scanOptions = null): string
    {
        $auditSummary = [];
        $scanCapabilities = $this->scanCapabilitiesResolver->resolve();
        $resolvedScanOptions = $this->scanCapabilitiesResolver->applyToScanOptions(
            $this->scanOptionsResolver->resolve($scanOptions),
            $scanCapabilities
        );
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
                'summaryJson' => [
                    'scanOptions' => $resolvedScanOptions,
                    'scanCapabilities' => $scanCapabilities,
                ],
            ],
        ], $context);

        $this->logger->info('EsmxShopAuditAi scan started', [
                'scanId' => $scanId,
                'startedAt' => $startedAt->format(DATE_ATOM),
                'scanCapabilities' => $scanCapabilities,
        ]);

        try {
            $productHealthOptions = $resolvedScanOptions['productHealth'] ?? null;
            $enabledProductHealthChecks = $this->resolveEnabledChecks($productHealthOptions);

            if (($productHealthOptions['enabled'] ?? true) && $enabledProductHealthChecks !== []) {
                $this->logger->info('EsmxShopAuditAi product health audit starting with scan options', [
                    'scanId' => $scanId,
                    'enabledProductHealthChecks' => $enabledProductHealthChecks,
                    'productHealthScanOptions' => $productHealthOptions,
                ]);

                $auditSummary = $this->productAuditService->buildProductAuditSummary($context, $enabledProductHealthChecks);
            } else {
                $this->logger->info('EsmxShopAuditAi product health audit skipped by scan options', [
                    'scanId' => $scanId,
                    'productHealthScanOptions' => $productHealthOptions,
                    'enabledProductHealthChecks' => $enabledProductHealthChecks,
                ]);

                $auditSummary = $this->buildEmptyAuditSummary();
            }

            $seoOptions = $resolvedScanOptions['seo'] ?? null;
            $enabledSeoChecks = $this->resolveEnabledChecks($seoOptions);

            if (($seoOptions['enabled'] ?? true) && $enabledSeoChecks !== []) {
                $this->logger->info('EsmxShopAuditAi SEO audit starting with scan options', [
                    'scanId' => $scanId,
                    'enabledSeoChecks' => $enabledSeoChecks,
                    'seoScanOptions' => $seoOptions,
                ]);

                $seoAuditResult = $this->seoAuditService->run($context, $enabledSeoChecks);
                $auditSummary = $this->productAuditService->mergeSeoAuditResultIntoSummary($auditSummary, $seoAuditResult);
            } else {
                $this->logger->info('EsmxShopAuditAi SEO audit skipped by scan options', [
                    'scanId' => $scanId,
                    'seoScanOptions' => $seoOptions,
                    'enabledSeoChecks' => $enabledSeoChecks,
                ]);
            }

            // Broken Link Audit Integration
            $brokenLinkOptions = $resolvedScanOptions['brokenLinks'] ?? null;
            $enabledBrokenLinkChecks = $this->resolveEnabledChecks($brokenLinkOptions);
            $brokenLinkEnabled = (bool) ($scanCapabilities['groups']['brokenLinks']['enabled'] ?? true);

            if ($brokenLinkEnabled && ($brokenLinkOptions['enabled'] ?? true) && $enabledBrokenLinkChecks !== []) {
                $maxLinks = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.brokenLinkMaxLinks') ?? 100);
                $timeout = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.brokenLinkTimeout') ?? 5);
                $checkExternalValue = $this->systemConfigService->get('EsmxShopAuditAi.config.brokenLinkCheckExternal');
                $checkExternal = $checkExternalValue === null ? true : (bool) $checkExternalValue;

                $this->logger->info('Broken link audit starting with scan options', [
                    'scanId' => $scanId,
                    'brokenLinksScanOptions' => $resolvedScanOptions['brokenLinks'] ?? null,
                    'enabledBrokenLinkChecks' => $enabledBrokenLinkChecks,
                    'maxLinks' => $maxLinks,
                    'timeout' => $timeout,
                    'checkExternal' => $checkExternal,
                ]);

                $brokenLinkResult = $this->brokenLinkAuditService->run(
                    $context,
                    $maxLinks,
                    $timeout,
                    $checkExternal,
                    $resolvedScanOptions
                );

                // Merge into audit summary
                $existingBrokenLinks = $auditSummary['issues']['broken_links'] ?? [];
                $newBrokenLinks = $brokenLinkResult['broken_links'] ?? [];

                $auditSummary['issues']['broken_links'] = array_values(array_merge(
                    \is_array($existingBrokenLinks) ? $existingBrokenLinks : [],
                    \is_array($newBrokenLinks) ? $newBrokenLinks : []
                ));

                $auditSummary['totals']['broken_links'] = \count($auditSummary['issues']['broken_links']);

                $this->logger->info('Broken link audit result', [
                    'count' => count($brokenLinkResult['broken_links'] ?? []),
                ]);
            } else {
                $this->logger->info('Broken link audit skipped by scan options or plugin settings', [
                    'scanId' => $scanId,
                    'brokenLinksScanOptions' => $brokenLinkOptions,
                    'enabledBrokenLinkChecks' => $enabledBrokenLinkChecks,
                    'brokenLinkCapabilityEnabled' => $brokenLinkEnabled,
                ]);
            }


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
                        'scanOptions' => $resolvedScanOptions,
                        'scanCapabilities' => $scanCapabilities,
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
                        'scanOptions' => $resolvedScanOptions,
                        'scanCapabilities' => $scanCapabilities,
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

    private function resolveEnabledChecks(?array $groupOptions): array
    {
        $checks = $groupOptions['checks'] ?? [];

        if (!\is_array($checks)) {
            return [];
        }

        return array_values(array_keys(array_filter(
            $checks,
            static fn ($enabled): bool => $enabled === true
        )));
    }

    private function buildEmptyAuditSummary(): array
    {
        return [
            'meta' => [
                'scannedProducts' => 0,
                'productLimit' => 0,
                'variantAuditMode' => null,
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
        ];
    }
}
