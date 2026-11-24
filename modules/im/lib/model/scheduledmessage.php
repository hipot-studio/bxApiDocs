<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\UpdateByFilterTrait;
use Bitrix\Im\V2\Message\Scheduled\ScheduledMessageStatus;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Text\Emoji;

/**
 * Class ScheduledMessageTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> CHAT_ID int mandatory
 * <li> AUTHOR_ID int mandatory
 * <li> UUID string(36) mandatory
 * <li> MESSAGE string optional
 * <li> SEND_AT datetime mandatory
 * <li> STATUS enum mandatory default 'scheduled' (see {@see \Bitrix\Im\V2\Message\Scheduled\ScheduledMessageStatus})
 * </ul>
 *
 * @package Bitrix\Im
 */

class ScheduledMessageTable extends DataManager
{
	use UpdateByFilterTrait;
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_im_scheduled_message';
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
				->configureAutocomplete(),

			(new IntegerField('CHAT_ID'))
				->configureRequired(),

			(new IntegerField('AUTHOR_ID'))
				->configureRequired(),

			(new StringField('UUID'))
				->configureRequired()
				->configureSize(36),

			(new TextField('MESSAGE'))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),

			(new DatetimeField('SEND_AT'))
				->configureRequired(),

			(new EnumField('STATUS'))
				->configureValues(array_column(ScheduledMessageStatus::cases(), 'value'))
				->configureRequired()
				->configureDefaultValue(ScheduledMessageStatus::Scheduled->value),
		];
	}
}
