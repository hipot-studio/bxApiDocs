<?php

namespace Bitrix\Sign\Internal\Process;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class LaunchTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Launch_Query query()
 * @method static EO_Launch_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Launch_Result getById($id)
 * @method static EO_Launch_Result getList(array $parameters = [])
 * @method static EO_Launch_Entity getEntity()
 * @method static \Bitrix\Sign\Internal\Process\EO_Launch createObject($setDefaultValues = true)
 * @method static \Bitrix\Sign\Internal\Process\EO_Launch_Collection createCollection()
 * @method static \Bitrix\Sign\Internal\Process\EO_Launch wakeUpObject($row)
 * @method static \Bitrix\Sign\Internal\Process\EO_Launch_Collection wakeUpCollection($rows)
 */
class LaunchTable extends Entity\DataManager
{
	public static function getTableName(): string
	{
		return 'b_sign_process_launch';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired()
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired()
			,
			(new IntegerField('ENTITY_TYPE_ID'))
				->configureRequired()
			,
			(new IntegerField('MEMBER_COUNT'))
				->configureRequired()
			,
			(new StringField('STATUS'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 20))
			,
			(new ArrayField('PARAMETERS'))
				->configureNullable()
			,
			(new DatetimeField('QUEUED_AT'))
				->configureRequired()
			,
			(new DatetimeField('EXPIRES_AT'))
				->configureRequired()
			,
		];
	}
}
