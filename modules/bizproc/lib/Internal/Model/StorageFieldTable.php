<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class StorageFieldTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> STORAGE_ID int mandatory
 * <li> CODE string(255) mandatory
 * <li> SORT int mandatory default 500
 * <li> NAME string(255) mandatory
 * <li> DESCRIPTION text optional
 * <li> TYPE string(50) mandatory
 * <li> MULTIPLE boolean mandatory
 * <li> MANDATORY boolean mandatory
 * <li> SETTINGS array optional
 * </ul>
 *
 * @package Bitrix\Bizproc\Internal\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_StorageField_Query query()
 * @method static EO_StorageField_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_StorageField_Result getById($id)
 * @method static EO_StorageField_Result getList(array $parameters = [])
 * @method static EO_StorageField_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection wakeUpCollection($rows)
 */
class StorageFieldTable extends DataManager
{
	use DeleteByFilterTrait;

	public const DEFAULT_SORT = 500;
	public const MAX_FIELDS_PER_STORAGE = 50;
	private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	public static function getTableName(): string
	{
		return 'b_bp_storage_field';
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
			(new StringField('CODE'))
				->addValidator(new LengthValidator(null, 100))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_CODE'))
			,
			(new IntegerField('SORT'))
				->configureDefaultValue(self::DEFAULT_SORT)
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_SORT'))
			,
			(new StringField('NAME'))
				->addValidator(new LengthValidator(null, 255))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_NAME'))
			,
			(new TextField('DESCRIPTION'))
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_DESCRIPTION'))
			,
			(new StringField('TYPE'))
				->addValidator(new LengthValidator(null, 50))
				->configureRequired()
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_TYPE'))
			,
			(new BooleanField('MULTIPLE'))
				->configureRequired()
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_MULTIPLE'))
			,
			(new BooleanField('MANDATORY'))
				->configureRequired()
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_MANDATORY'))
			,
			(new ArrayField('SETTINGS'))
				->configureTitle(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_SETTINGS'))
			,
		];
	}

	public static function onBeforeAdd(Event $event): EventResult
	{
		$fields = $event->getParameter('fields');
		$result = new EventResult();

		if (!isset($fields['STORAGE_ID']))
		{
			return $result;
		}

		$storageId = (int)$fields['STORAGE_ID'];
		if ($storageId <= 0)
		{
			return $result;
		}

		$currentCount = static::getFieldsCountByStorage($storageId);

		if ($currentCount >= static::MAX_FIELDS_PER_STORAGE)
		{
			$result->addError(new EntityError(
				Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_LIMIT_EXCEEDED', [
					'#LIMIT#' => static::MAX_FIELDS_PER_STORAGE,
				])
			));
		}

		$code = trim($fields['CODE']);
		if ($code !== '' && !preg_match(self::CODE_PATTERN, $code))
		{
			$result->addError(new EntityError(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_WRONG_CODE') ?? ''));
		}

		$map = StorageFieldMapper::getFieldsMap();
		if (array_key_exists(mb_strtoupper($code), $map) && $map[mb_strtoupper($code)] !== null)
		{
			$result->addError(new EntityError(Loc::getMessage('BIZPROC_STORAGE_FIELD_MODEL_FIELD_CODE_EXIST') ?? ''));
		}

		return $result;
	}

	public static function getFieldsCountByStorage(int $storageId): int
	{
		if ($storageId <= 0)
		{
			return 0;
		}

		return static::getCount(['=STORAGE_ID' => $storageId]);
	}
}
