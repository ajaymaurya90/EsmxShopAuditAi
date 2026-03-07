<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task;

use EsmxShopAuditAi\Core\Content\Scan\ScanDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

class TaskDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'esmx_shop_audit_task';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TaskEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TaskCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new FkField('scan_id', 'scanId', ScanDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new StringField('code', 'code'))->addFlags(new Required(), new ApiAware()),
            (new StringField('title', 'title'))->addFlags(new Required(), new ApiAware()),
            (new StringField('priority', 'priority'))->addFlags(new Required(), new ApiAware()),
            (new IntField('affected_count', 'affectedCount'))->addFlags(new Required(), new ApiAware()),
            (new StringField('status', 'status'))->addFlags(new Required(), new ApiAware()),
            (new JsonField('payload_json', 'payloadJson'))->addFlags(new ApiAware()),
            new ManyToOneAssociationField('scan', 'scan_id', ScanDefinition::class, 'id', false),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}