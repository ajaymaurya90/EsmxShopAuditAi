<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo;

final class SeoAuditResult
{
    /**
     * @param array<string, array<string, mixed>> $issues
     */
    public function __construct(
        private readonly array $issues,
        private readonly SeoAuditKpiResult $kpi
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getKpi(): SeoAuditKpiResult
    {
        return $this->kpi;
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    public function getIssueGroupCount(): int
    {
        return \count($this->issues);
    }

    public function getAffectedItemCount(): int
    {
        $count = 0;

        foreach ($this->issues as $issue) {
            $items = $issue['items'] ?? [];

            if (\is_array($items)) {
                $count += \count($items);
            }
        }

        return $count;
    }

    public function toArray(): array
    {
        return [
            'issues' => $this->issues,
            'kpi' => $this->kpi->toArray(),
        ];
    }
}