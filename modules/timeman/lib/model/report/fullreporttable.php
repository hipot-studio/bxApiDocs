<?php

namespace Bitrix\Timeman\Model\Report;

use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Data\DataManager;

class FullReportTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_timeman_report_full';
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true),

			(new Fields\DatetimeField('TIMESTAMP_X')),
			(new Fields\BooleanField('ACTIVE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('Y'),

			(new Fields\IntegerField('USER_ID'))
				->configureDefaultValue(0),

			(new Fields\DatetimeField('REPORT_DATE')),
			(new Fields\DatetimeField('DATE_FROM')),
			(new Fields\DatetimeField('DATE_TO')),

			(new Fields\TextField('TASKS')),
			(new Fields\TextField('EVENTS')),
			(new Fields\TextField('FILES')),
			(new Fields\TextField('REPORT')),
			(new Fields\TextField('PLANS')),

			(new Fields\StringField('MARK'))
				->addValidator(new Fields\Validators\LengthValidator(null, 1))
				->configureDefaultValue('N'),

			(new Fields\StringField('APPROVE'))
				->addValidator(new Fields\Validators\LengthValidator(null, 1))
				->configureDefaultValue('N'),

			(new Fields\DatetimeField('APPROVE_DATE')),
			(new Fields\IntegerField('APPROVER'))
				->configureDefaultValue(0),

			(new Fields\IntegerField('FORUM_TOPIC_ID'))
				->configureDefaultValue(0),
		];
	}
}

