<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class ScheduledMessageTable extends DataManager
{
	use DeleteByFilterTrait;

	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_SENDING = 'sending';
	public const STATUS_ERROR = 'error';
	public const STATUS_SENT = 'sent';
	public const STATUS_CANCELLED = 'cancelled';
	public const MODE_SCHEDULED = 'scheduled';
	public const MODE_UNDO = 'undo';
	public const SOURCE_TYPE_MAIL = 'mail';
	public const SOURCE_TYPE_CRM = 'crm';

	public static function getTableName(): string
	{
		return 'b_mail_scheduled_message';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('AUTHOR_ID'))
				->configureRequired()
			,
			(new IntegerField('MAILBOX_ID'))
			,
			(new StringField('MODE'))
				->configureSize(16)
				->configureRequired()
				->configureDefaultValue(self::MODE_SCHEDULED)
			,
			(new StringField('SOURCE_TYPE'))
				->configureSize(16)
				->configureRequired()
				->configureDefaultValue(self::SOURCE_TYPE_MAIL)
			,
			new DatetimeField('CANCEL_UNTIL'),
			self::sourceContextField(),
			(new StringField('IDEMPOTENCY_KEY'))
				->configureSize(64)
			,
			(new JsonField('SENDER'))
				->configureRequired()
			,
			(new JsonField('RECIPIENTS'))
				->configureRequired()
			,
			(new StringField('SUBJECT'))
				->configureSize(255)
				->configureDefaultValue('')
			,
			(new TextField('BODY'))
				->configureRequired()
			,
			self::largeAttachmentsField(),
			(new StringField('IN_REPLY_TO'))
				->configureSize(255)
			,
			(new DatetimeField('SCHEDULED_AT'))
				->configureRequired()
			,
			(new StringField('AUTHOR_TIMEZONE_ID'))
				->configureSize(255)
				->configureRequired()
			,
			(new StringField('STATUS'))
				->configureSize(16)
				->configureRequired()
				->configureDefaultValue(self::STATUS_SCHEDULED)
			,
			(new IntegerField('ATTEMPTS'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			new DatetimeField('NEXT_ATTEMPT_AT'),
			new DatetimeField('DISPATCH_RECEIPT_AT'),
			self::lastErrorField(),
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(1)
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
			,
		];
	}

	private static function sourceContextField(): JsonField
	{
		return (new class ('SOURCE_CONTEXT') extends JsonField {
			public function decode($value)
			{
				if ($value === null || $value === '')
				{
					return null;
				}

				return parent::decode($value);
			}
		})->configureNullable(true);
	}

	private static function largeAttachmentsField(): JsonField
	{
		return (new class ('LARGE_ATTACHMENTS') extends JsonField {
			public function decode($value)
			{
				if ($value === null || $value === '')
				{
					return [];
				}

				return parent::decode($value);
			}
		})->configureNullable(true);
	}

	/**
	 * The column is nullable, and the standard field parses whatever it holds. A page of the section is read by
	 * one query, so a value that cannot be parsed aborts the query and empties the whole page instead of leaving
	 * one row without a reason. Every write of the feature stores json, so this only meets what was written
	 * around it — a migration, a fixture, a row edited by hand — and reads it as "no reason recorded".
	 */
	private static function lastErrorField(): JsonField
	{
		return new class ('LAST_ERROR') extends JsonField {
			public function decode($value)
			{
				if ($value === null || trim((string)$value) === '')
				{
					return null;
				}

				try
				{
					return parent::decode($value);
				}
				catch (ArgumentException)
				{
					return null;
				}
			}
		};
	}
}
