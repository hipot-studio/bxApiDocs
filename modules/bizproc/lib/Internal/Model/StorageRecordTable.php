<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class StorageRecordTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> STORAGE_ID int mandatory
 * <li> DOCUMENT_ID string(128) mandatory
 * <li> WORKFLOW_ID string(32) mandatory
 * <li> TEMPLATE_ID int mandatory
 * <li> CREATED_BY int mandatory
 * <li> UPDATED_BY int mandatory
 * <li> CREATED_TIME datetime optional default current datetime
 * <li> UPDATED_TIME datetime optional default current datetime
 * </ul>
 *
 * @package Bitrix\Bizproc\Internal\Model
 */
class StorageRecordTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_storage_record_data';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new IntegerField('STORAGE_ID'))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_STORAGE_ID'))
			,
			(new ORM\Fields\StringField('DOCUMENT_ID'))
				->configureRequired()
				->configureSize(128)
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_DOCUMENT_ID'))
			,
			(new ORM\Fields\StringField('WORKFLOW_ID'))
				->configureRequired()
				->configureSize(32)
				->addValidator(new ORM\Fields\Validators\LengthValidator(1, 32))
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_WORKFLOW_ID'))
			,
			(new ORM\Fields\IntegerField('TEMPLATE_ID'))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_TEMPLATE_ID'))
			,
			(new ORM\Fields\IntegerField('CREATED_BY'))
				->configureRequired()
				->configureDefaultValue(self::getCurrentUserId())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_CREATED_BY'))
			,
			(new ORM\Fields\IntegerField('UPDATED_BY'))
				->configureRequired()
				->configureDefaultValue(self::getCurrentUserId())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_UPDATED_BY'))
			,
			(new ORM\Fields\DatetimeField('CREATED_TIME'))
				->configureRequired()
				->configureDefaultValue(new DateTime())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_CREATED_TIME'))
			,
			(new ORM\Fields\DatetimeField('UPDATED_TIME'))
				->configureRequired()
				->configureDefaultValue(new DateTime())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_ITEM_MODEL_FIELD_UPDATED_TIME'))
			,
		];
	}

	private static function getCurrentUserId(): int
	{
		global $USER;

		if (is_object($USER))
		{
			return (int) CurrentUser::get()->getId();
		}

		return 0;
	}
}
