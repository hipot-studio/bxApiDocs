<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Entity;

/**
 * Class MailboxSourceGenerationTable
 *
 * Physical IMAP source generations of a logical mailbox. The single source of truth
 * for IMAP credentials of every generation; connection fields of b_mail_mailbox are
 * a legacy projection of the active generation maintained by the switch service only.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailboxSourceGeneration_Query query()
 * @method static EO_MailboxSourceGeneration_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailboxSourceGeneration_Result getById($id)
 * @method static EO_MailboxSourceGeneration_Result getList(array $parameters = [])
 * @method static EO_MailboxSourceGeneration_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailboxSourceGeneration_Collection wakeUpCollection($rows)
 */
class MailboxSourceGenerationTable extends Entity\DataManager
{
	public const STATUS_PREPARING = 'PREPARING';
	public const STATUS_ACTIVE = 'ACTIVE';
	public const STATUS_ARCHIVED = 'ARCHIVED';

	/** The deterministic operation of the G1 backfill: marks the initial generation of a mailbox. */
	public const OPERATION_G1_BACKFILL = 'g1-backfill';

	/**
	 * OPTIONS key of the managed transfer service the operation declared, empty for an
	 * operation nobody carries out for us.
	 */
	public const OPTION_MIGRATOR_SERVICE = 'migrator_service';

	/** OPTIONS key confirming that the source system authorized the final switch. */
	public const OPTION_FINAL_SWITCH_AUTHORIZED = 'final_switch_authorized';

	/** OPTIONS key that stops migration passes and hands the generation to cancellation cleanup. */
	public const OPTION_CANCEL_REQUESTED = 'migration_cancel_requested';

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_mailbox_source_generation';
	}

	/**
	 * The generations the mailbox is preparing right now: the ones a migration fills with a source
	 * the user has not been handed over to and may not see.
	 *
	 * The G1 backfill is none of them, whatever its status says. It adopts the source the mailbox
	 * is already served by, so its rows are the history the user has been looking at all along -
	 * the walk only stamps a column on them - and that generation can stay PREPARING for good,
	 * because the rows are assigned before the sync lock is taken and a busy lock stops the
	 * finalization. Counted as prepared, it was hidden from the read of the logical identity of a
	 * letter, and a letter that gained a placement came back as a second letter.
	 *
	 * Two renderings of one condition, and both live here because neither can be had from the
	 * other: {@see preparedGenerationSqlCondition()} serves a reader that must not touch the ORM
	 * map of another release. Editing one means editing the other.
	 */
	public static function preparedGenerationFilter(int $mailboxId): array
	{
		return [
			'=MAILBOX_ID' => $mailboxId,
			'=STATUS' => self::STATUS_PREPARING,
			'!=OPERATION_ID' => self::OPERATION_G1_BACKFILL,
		];
	}

	/**
	 * The same condition as a piece of SQL, for a query built without the ORM.
	 *
	 * Two of the three values it names are constants of this class; the third is the mailbox of
	 * the caller, and what makes it safe is its own type - an int placed by %u, which renders
	 * digits and nothing else. Escaping is therefore not what this condition rests on, and adding
	 * a value of any other kind here would need a way of its own.
	 *
	 * Returned parenthesized: a chain of AND handed out as a string is a piece a caller may join
	 * with an OR, and AND binds tighter - the condition on the mailbox would end up in one branch
	 * of that OR alone.
	 *
	 * {@see preparedGenerationFilter()} tells why the two renderings exist and what keeps them one
	 * answer.
	 *
	 * @internal Public for {@see \Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope} only.
	 */
	public static function preparedGenerationSqlCondition(int $mailboxId): string
	{
		return sprintf(
			"(MAILBOX_ID = %u AND STATUS = '%s' AND OPERATION_ID <> '%s')",
			$mailboxId,
			self::STATUS_PREPARING,
			self::OPERATION_G1_BACKFILL,
		);
	}

	/**
	 * The password of a generation is the password of the mailbox: the switch projects one
	 * into the other, so the two are the same credential and must be protected alike.
	 *
	 * Crypto is switched on per table name, and this table is nobody's default, so the
	 * setting of the mailbox is asked instead of one of its own. Without that the snapshot
	 * would silently stay on the legacy encryption of the module while the mailbox moved on.
	 */
	public static function passwordCryptoEnabled(): bool
	{
		return static::cryptoEnabled('PASSWORD', MailboxTable::getTableName());
	}

	public static function getMap()
	{
		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'MAILBOX_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'OPERATION_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'STATUS' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::STATUS_PREPARING,
					self::STATUS_ACTIVE,
					self::STATUS_ARCHIVED,
				],
			],
			'REVISION' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'SERVICE_ID' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'SERVICE_NAME' => [
				'data_type' => 'string',
			],
			'EMAIL' => [
				'data_type' => 'string',
			],
			'SERVER' => [
				'data_type' => 'string',
			],
			'PORT' => [
				'data_type' => 'integer',
			],
			'USE_TLS' => [
				'data_type' => 'enum',
				'values' => ['N', 'Y', 'S'],
			],
			'LOGIN' => [
				'data_type' => 'string',
			],
			'PASSWORD' => [
				'data_type' => (static::passwordCryptoEnabled() ? 'crypto' : 'string'),
				'save_data_modification' => function ()
				{
					return [
						function ($value)
						{
							return static::passwordCryptoEnabled() ? $value : \CMailUtil::crypt($value);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($value)
						{
							return static::passwordCryptoEnabled() ? $value : \CMailUtil::decrypt($value);
						},
					];
				},
			],
			'SMTP_SERVER' => [
				'data_type' => 'string',
			],
			'SMTP_PORT' => [
				'data_type' => 'integer',
			],
			'SMTP_PROTOCOL' => [
				'data_type' => 'enum',
				'values' => ['smtp', 'smtps'],
			],
			'SMTP_LOGIN' => [
				'data_type' => 'string',
			],
			'SMTP_PASSWORD' => [
				'data_type' => (static::passwordCryptoEnabled() ? 'crypto' : 'string'),
				'save_data_modification' => function ()
				{
					return [
						function ($value)
						{
							if ($value === null)
							{
								return null;
							}

							return static::passwordCryptoEnabled() ? $value : \CMailUtil::crypt($value);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($value)
						{
							if ($value === null)
							{
								return null;
							}

							return static::passwordCryptoEnabled() ? $value : \CMailUtil::decrypt($value);
						},
					];
				},
			],
			'SMTP_LIMIT' => [
				'data_type' => 'integer',
			],
			'OPTIONS' => [
				'data_type' => 'text',
				'save_data_modification' => function ()
				{
					return [
						function ($options)
						{
							return serialize($options);
						},
					];
				},
				'fetch_data_modification' => function ()
				{
					return [
						function ($values)
						{
							return unserialize($values, ['allowed_classes' => false]);
						},
					];
				},
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_ACTIVATED' => [
				'data_type' => 'datetime',
			],
			'DATE_ARCHIVED' => [
				'data_type' => 'datetime',
			],
			'MAILBOX' => [
				'data_type' => 'Bitrix\Mail\Mailbox',
				'reference' => ['=this.MAILBOX_ID' => 'ref.ID'],
			],
		];
	}
}
