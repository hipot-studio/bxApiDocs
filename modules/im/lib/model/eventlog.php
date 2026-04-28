<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Main;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

/**
 * Class EventLogTable
 *
 * Fields:
 * <ul>
 * <li> ID bigint mandatory
 * <li> USER_ID int mandatory
 * <li> EVENT_TYPE string(50) mandatory
 * <li> EVENT_DATA longtext mandatory
 * <li> DATE_CREATE datetime mandatory
 * </ul>
 *
 * @package Bitrix\Im
 **/

class EventLogTable extends Main\Entity\DataManager
{
	use DeleteTrait;

	public static function getTableName(): string
	{
		return 'b_im_event_log';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new StringField('EVENT_TYPE'))
				->configureRequired()
				->configureSize(50),
			(new TextField('EVENT_DATA'))
				->configureRequired()
				->configureLong()
				->addSaveDataModifier(['\Bitrix\Main\Text\Emoji', 'encode'])
				->addFetchDataModifier(['\Bitrix\Main\Text\Emoji', 'decode']),
			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
