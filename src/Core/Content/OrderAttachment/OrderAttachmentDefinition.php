<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * DAL-Definition für `rc_order_attachment`. Verheiratet einen Customer-Upload
 * (Media-Eintrag) mit einer Bestellung. Pflichtfelder werden in der Migration
 * abgedeckt; die `ReferenceVersionField` auf `OrderDefinition` ist Senior-Pflicht,
 * damit Live- und Draft-Versionen der Order nicht vermischt werden.
 */
final class OrderAttachmentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_order_attachment';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return OrderAttachmentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return OrderAttachmentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))
                ->addFlags(new ApiAware(), new Required(), new PrimaryKey()),
            (new FkField('order_id', 'orderId', OrderDefinition::class))
                ->addFlags(new ApiAware(), new Required()),
            (new ReferenceVersionField(OrderDefinition::class))
                ->addFlags(new ApiAware(), new Required()),
            (new FkField('media_id', 'mediaId', MediaDefinition::class))
                ->addFlags(new ApiAware(), new Required()),
            (new StringField('original_file_name', 'originalFileName'))
                ->addFlags(new ApiAware(), new Required()),
            (new StringField('mime_type', 'mimeType'))
                ->addFlags(new ApiAware(), new Required()),
            (new IntField('file_size', 'fileSize'))
                ->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),
            (new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
            (new CreatedAtField())->addFlags(new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),
        ]);
    }
}
