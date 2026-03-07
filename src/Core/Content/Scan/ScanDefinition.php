<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingDefinition;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

class ScanDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'esmx_shop_audit_scan';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ScanEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ScanCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new StringField('status', 'status'))->addFlags(new Required(), new ApiAware()),
            (new DateTimeField('started_at', 'startedAt'))->addFlags(new ApiAware()),
            (new DateTimeField('finished_at', 'finishedAt'))->addFlags(new ApiAware()),
            (new IntField('scanned_products', 'scannedProducts'))->addFlags(new Required(), new ApiAware()),
            (new IntField('total_findings', 'totalFindings'))->addFlags(new Required(), new ApiAware()),
            (new IntField('high_priority_findings', 'highPriorityFindings'))->addFlags(new Required(), new ApiAware()),
            (new JsonField('summary_json', 'summaryJson'))->addFlags(new ApiAware()),
            new OneToManyAssociationField('findings', FindingDefinition::class, 'scan_id'),
            new OneToManyAssociationField('tasks', TaskDefinition::class, 'scan_id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}