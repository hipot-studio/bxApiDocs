<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class ScheduledActionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ScheduledAction_Query query()
 * @method static EO_ScheduledAction_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ScheduledAction_Result getById($id)
 * @method static EO_ScheduledAction_Result getList(array $parameters = [])
 * @method static EO_ScheduledAction_Entity getEntity()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ScheduledAction createObject($setDefaultValues = true)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ScheduledAction_Collection createCollection()
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ScheduledAction wakeUpObject($row)
 * @method static \Bitrix\Timeman\V2\Internal\Model\EO_ScheduledAction_Collection wakeUpCollection($rows)
 */
class ScheduledActionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_timeman_scheduled_action';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 100)),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new IntegerField('EXECUTE_TIME'))
				->configureRequired(),
			(new StringField('STATUS'))
				->configureRequired()
				->configureDefaultValue('pending')
				->addValidator(new LengthValidator(null, 16)),
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime()),
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime()),
		];
	}
}
