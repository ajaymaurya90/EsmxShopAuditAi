<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ScanEntity>
 */
class ScanCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ScanEntity::class;
    }
}