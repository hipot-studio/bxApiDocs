<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Vacation auto-reply setting of a mailbox: one row per mailbox, keyed by it.
 *
 * STARTS_AT and ENDS_AT hold machine time; USER_TIMEZONE_ID keeps the timezone their bounds were
 * given in, so the period can be shown back the way it was set.
 */
final class VacationAutoReplyTable extends DataManager
{
	use DeleteByFilterTrait;

	public const ACTIVE_YES = 'Y';
	public const ACTIVE_NO = 'N';

	public const FIRST_PERIOD_ID = 1;

	public static function getTableName(): string
	{
		return 'b_mail_vacation_auto_reply';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('MAILBOX_ID'))->configurePrimary(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new BooleanField('ACTIVE'))
				->configureValues(self::ACTIVE_NO, self::ACTIVE_YES)
				->configureRequired()
				->configureDefaultValue(self::ACTIVE_NO),
			// Bumped when a new period starts; part of the dedup key of VacationAutoReplyLogTable
			(new IntegerField('PERIOD_ID'))->configureRequired()->configureDefaultValue(self::FIRST_PERIOD_ID),
			(new DatetimeField('STARTS_AT'))->configureRequired(),
			(new DatetimeField('ENDS_AT'))->configureRequired(),
			(new StringField('USER_TIMEZONE_ID'))->configureRequired()->configureSize(255),
			(new TextField('BODY'))->configureRequired(),
			// Mailbox PERIOD_CHECK saved before the period shortened it, restored when the period ends
			new IntegerField('ORIGINAL_PERIOD_CHECK'),
			// The value the period wrote into PERIOD_CHECK instead: what tells the writing of this feature
			// from the writing of another owner of the field, whatever the option says by then
			new IntegerField('SHORTENED_PERIOD_CHECK'),
			(new DatetimeField('CREATED_AT'))->configureRequired(),
			(new DatetimeField('UPDATED_AT'))->configureRequired(),
		];
	}
}
