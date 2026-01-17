<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;

/**
 * Class StorageTypeTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TITLE string(255) mandatory
 * <li> DESCRIPTION text optional
 * <li> CREATED_BY int mandatory
 * <li> UPDATED_BY int mandatory
 * <li> CREATED_TIME datetime optional default current datetime
 * <li> UPDATED_TIME datetime optional default current datetime
 * </ul>
 *
 * @package Bitrix\Bizproc\Internal\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_StorageType_Query query()
 * @method static EO_StorageType_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_StorageType_Result getById($id)
 * @method static EO_StorageType_Result getList(array $parameters = [])
 * @method static EO_StorageType_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection wakeUpCollection($rows)
 */
class StorageTypeTable  extends DataManager
{
	public const MAX_STORAGES = 300;
	private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_bp_storage_type';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new StringField('TITLE'))
				->addValidator(new LengthValidator(null, 255))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_TITLE'))
			,
			(new StringField('CODE'))
				->addValidator(new LengthValidator(null, 64))
				->configureUnique()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_CODE'))
			,
			(new TextField('DESCRIPTION'))
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_DESCRIPTION'))
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_CREATED_BY'))
			,
			(new IntegerField('UPDATED_BY'))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_UPDATED_BY'))
			,
			(new DatetimeField('CREATED_TIME'))
				->configureDefaultValue(new DateTime())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_CREATED_TIME'))
			,
			(new DatetimeField('UPDATED_TIME'))
				->configureDefaultValue(new DateTime())
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_UPDATED_TIME'))
			,
		];
	}

	public static function onBeforeAdd(Event $event): EventResult
	{
		$fields = $event->getParameter('fields');
		$result = new EventResult();

		$currentCount = static::getCount();

		if ($currentCount >= static::MAX_STORAGES)
		{
			$result->addError(new EntityError(
				Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_LIMIT_EXCEEDED', [
					'#LIMIT#' => static::MAX_STORAGES,
				])
			));
		}

		$code = trim($fields['CODE'] ?? '');
		if ($code !== '' && !preg_match(self::CODE_PATTERN, $code))
		{
			$result->addError(new EntityError(Loc::getMessage('BIZPROC_STORAGE_TYPE_MODEL_FIELD_WRONG_CODE') ?? ''));
		}

		return $result;
	}
}
