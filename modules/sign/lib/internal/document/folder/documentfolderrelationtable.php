<?php

namespace Bitrix\Sign\Internal\Document\Folder;

use Bitrix\Main\Entity;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Sign\Internal\Document\DocumentFolderTable;
use Bitrix\Sign\Internal\MemberTable;
use Bitrix\Sign\Trait\ORM\UpdateByFilterTrait;
use Bitrix\Sign\Type\Document\Folder\EntityType;

/**
 * Class DocumentFolderRelationTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentFolderRelation_Query query()
 * @method static EO_DocumentFolderRelation_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentFolderRelation_Result getById($id)
 * @method static EO_DocumentFolderRelation_Result getList(array $parameters = [])
 * @method static EO_DocumentFolderRelation_Entity getEntity()
 * @method static \Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelation createObject($setDefaultValues = true)
 * @method static \Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelationCollection createCollection()
 * @method static \Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelation wakeUpObject($row)
 * @method static \Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelationCollection wakeUpCollection($rows)
 */
class DocumentFolderRelationTable extends Entity\DataManager
{
	use DeleteByFilterTrait;
	use UpdateByFilterTrait;

	public static function getObjectClass(): string
	{
		return DocumentFolderRelation::class;
	}

	public static function getTableName(): string
	{
		return 'b_sign_document_folder_relation';
	}

	public static function getCollectionClass(): string
	{
		return DocumentFolderRelationCollection::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('ENTITY_ID'))
				->configureTitle('Entity ID')
				->configureSize(8)
				->configureRequired()
			,
			(new StringField('ENTITY_TYPE'))
				->configureTitle('Entity type')
				->addValidator(new LengthValidator(1, 50))
				->configureRequired()
			,
			(new IntegerField('PARENT_ID'))
				->configureTitle('Parent ID')
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new IntegerField('DEPTH_LEVEL'))
				->configureTitle('Depth level')
				->configureRequired()
			,
			(new IntegerField('CREATED_BY_ID'))
				->configureTitle('Created by id')
				->configureRequired()
			,
			(new ReferenceField(
				'FOLDER',
				DocumentFolderTable::class,
				Join::on('this.ENTITY_ID', 'ref.ID')->where('this.ENTITY_TYPE', EntityType::FOLDER->value)
			))
			,
			(new ReferenceField(
				'MEMBER',
				MemberTable::class,
				Join::on('this.ENTITY_ID', 'ref.ID')->where('this.ENTITY_TYPE', EntityType::MEMBER->value)
			))
			,
		];
	}
}
