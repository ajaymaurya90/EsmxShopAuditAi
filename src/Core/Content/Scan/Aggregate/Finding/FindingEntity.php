<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding;

use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class FindingEntity extends Entity
{
    use EntityIdTrait;

    protected string $scanId;

    protected string $code;

    protected string $title;

    protected string $severity;

    protected string $entity;

    protected int $affectedCount = 0;

    protected ?array $payloadJson = null;

    protected ?ScanEntity $scan = null;

    public function getScanId(): string
    {
        return $this->scanId;
    }

    public function setScanId(string $scanId): void
    {
        $this->scanId = $scanId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): void
    {
        $this->severity = $severity;
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function setEntity(string $entity): void
    {
        $this->entity = $entity;
    }

    public function getAffectedCount(): int
    {
        return $this->affectedCount;
    }

    public function setAffectedCount(int $affectedCount): void
    {
        $this->affectedCount = $affectedCount;
    }

    public function getPayloadJson(): ?array
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(?array $payloadJson): void
    {
        $this->payloadJson = $payloadJson;
    }

    public function getScan(): ?ScanEntity
    {
        return $this->scan;
    }

    public function setScan(?ScanEntity $scan): void
    {
        $this->scan = $scan;
    }
}