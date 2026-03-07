<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingCollection;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ScanEntity extends Entity
{
    use EntityIdTrait;

    protected string $status;

    protected ?\DateTimeInterface $startedAt = null;

    protected ?\DateTimeInterface $finishedAt = null;

    protected int $scannedProducts = 0;

    protected int $totalFindings = 0;

    protected int $highPriorityFindings = 0;

    protected ?array $summaryJson = null;

    protected ?FindingCollection $findings = null;

    protected ?TaskCollection $tasks = null;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getScannedProducts(): int
    {
        return $this->scannedProducts;
    }

    public function setScannedProducts(int $scannedProducts): void
    {
        $this->scannedProducts = $scannedProducts;
    }

    public function getTotalFindings(): int
    {
        return $this->totalFindings;
    }

    public function setTotalFindings(int $totalFindings): void
    {
        $this->totalFindings = $totalFindings;
    }

    public function getHighPriorityFindings(): int
    {
        return $this->highPriorityFindings;
    }

    public function setHighPriorityFindings(int $highPriorityFindings): void
    {
        $this->highPriorityFindings = $highPriorityFindings;
    }

    public function getSummaryJson(): ?array
    {
        return $this->summaryJson;
    }

    public function setSummaryJson(?array $summaryJson): void
    {
        $this->summaryJson = $summaryJson;
    }

    public function getFindings(): ?FindingCollection
    {
        return $this->findings;
    }

    public function setFindings(FindingCollection $findings): void
    {
        $this->findings = $findings;
    }

    public function getTasks(): ?TaskCollection
    {
        return $this->tasks;
    }

    public function setTasks(TaskCollection $tasks): void
    {
        $this->tasks = $tasks;
    }
}