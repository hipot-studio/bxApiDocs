<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Psr\Log\LogLevel;

/**
 * Class CalendarLogTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CalendarLog_Query query()
 * @method static EO_CalendarLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CalendarLog_Result getById($id)
 * @method static EO_CalendarLog_Result getList(array $parameters = [])
 * @method static EO_CalendarLog_Entity getEntity()
 * @method static \Bitrix\Calendar\Synchronization\Internal\Model\EO_CalendarLog createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Synchronization\Internal\Model\EO_CalendarLog_Collection createCollection()
 * @method static \Bitrix\Calendar\Synchronization\Internal\Model\EO_CalendarLog wakeUpObject($row)
 * @method static \Bitrix\Calendar\Synchronization\Internal\Model\EO_CalendarLog_Collection wakeUpCollection($rows)
 */
class CalendarLogTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_calendar_log';
	}

	/**
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new DatetimeField('TIMESTAMP_X')),
			(new StringField('LEVEL'))
				->configureRequired()
				->addValidator(new LengthValidator(4, 9))
				->configureDefaultValue(LogLevel::DEBUG)
			,
			(new TextField('MESSAGE')),
			(new StringField('TYPE')),
			(new StringField('UUID')),
			(new IntegerField('USER_ID')),
			(new TextField('CONTEXT')),
		];
	}
}
