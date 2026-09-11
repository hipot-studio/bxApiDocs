<?php

namespace Bitrix\Sign\Internal\Document;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class DocumentFolderTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentFolder_Query query()
 * @method static EO_DocumentFolder_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentFolder_Result getById($id)
 * @method static EO_DocumentFolder_Result getList(array $parameters = [])
 * @method static EO_DocumentFolder_Entity getEntity()
 * @method static \Bitrix\Sign\Internal\Document\DocumentFolder createObject($setDefaultValues = true)
 * @method static \Bitrix\Sign\Internal\Document\DocumentFolderCollection createCollection()
 * @method static \Bitrix\Sign\Internal\Document\DocumentFolder wakeUpObject($row)
 * @method static \Bitrix\Sign\Internal\Document\DocumentFolderCollection wakeUpCollection($rows)
 */
class DocumentFolderTable extends Entity\DataManager
{
	use DeleteByFilterTrait;

	public static function getObjectClass(): string
	{
		return DocumentFolder::class;
	}

	public static function getCollectionClass(): string
	{
		return DocumentFolderCollection::class;
	}

	public static function getTableName(): string
	{
		return 'b_sign_document_folder';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('TITLE'))
				->configureRequired()
				->addValidator(new Entity\Validator\Length(null, 255))
			,
			(new IntegerField('CREATED_BY_ID'))
				->configureRequired()
			,
			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
			,
			(new IntegerField('VISIBILITY'))
				->configureRequired()
			,
			(new IntegerField('STATUS'))
				->configureRequired()
			,
			(new IntegerField('MODIFIED_BY_ID'))
				->configureNullable()
			,
			(new DatetimeField('DATE_MODIFY'))
				->configureNullable()
			,
		];
	}
}
