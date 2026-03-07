<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<TaskEntity>
 */
class TaskCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaskEntity::class;
    }
}