<?php

namespace Bitrix\Sign\Internal\Process;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class LaunchItemTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_LaunchItem_Query query()
 * @method static EO_LaunchItem_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_LaunchItem_Result getById($id)
 * @method static EO_LaunchItem_Result getList(array $parameters = [])
 * @method static EO_LaunchItem_Entity getEntity()
 * @method static \Bitrix\Sign\Internal\Process\EO_LaunchItem createObject($setDefaultValues = true)
 * @method static \Bitrix\Sign\Internal\Process\EO_LaunchItem_Collection createCollection()
 * @method static \Bitrix\Sign\Internal\Process\EO_LaunchItem wakeUpObject($row)
 * @method static \Bitrix\Sign\Internal\Process\EO_LaunchItem_Collection wakeUpCollection($rows)
 */
class LaunchItemTable extends Entity\DataManager
{
	public static function getTableName(): string
	{
		return 'b_sign_process_launch_item';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('LAUNCH_ID'))
				->configureRequired()
			,
			(new IntegerField('EMPLOYEE_ID'))
				->configureRequired()
			,
			(new IntegerField('ENTITY_ID'))
				->configureNullable()
			,
			(new StringField('WORKFLOW_ID'))
				->configureNullable()
				->addValidator(new LengthValidator(null, 32))
			,
			(new StringField('STATUS'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 20))
			,
			(new StringField('ERROR_MESSAGE'))
				->configureNullable()
				->addValidator(new LengthValidator(null, 255))
			,
		];
	}
}
