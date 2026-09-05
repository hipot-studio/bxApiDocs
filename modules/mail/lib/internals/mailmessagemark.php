<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class MailMessageMarkTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailMessageMark_Query query()
 * @method static EO_MailMessageMark_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailMessageMark_Result getById($id)
 * @method static EO_MailMessageMark_Result getList(array $parameters = [])
 * @method static EO_MailMessageMark_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_MailMessageMark createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_MailMessageMark_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_MailMessageMark wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_MailMessageMark_Collection wakeUpCollection($rows)
 */
class MailMessageMarkTable extends Entity\DataManager
{
	use DeleteByFilterTrait;

	/**
	 * Mark kinds. Numeric to keep the composite primary key small: both indexes carry it.
	 * Never reuse a retired code - old rows may still be around.
	 */
	public const CODE_FAVORITES = 1;
	public const CODE_CLASSIFICATION_URGENT = 2;
	public const CODE_CLASSIFICATION_RISKY = 3;
	public const CODE_CLASSIFICATION_LOST = 4;

	/** Mailbox-wide marks are stored under this user id, so queries must filter USER_ID. */
	public const SHARED_USER_ID = 0;

	public const DELETE_CHUNK_SIZE = 1000;

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_message_mark';
	}

	public static function insertIgnore(int $mailboxId, int $messageId, int $code, int $userId): void
	{
		$connection = self::getEntity()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		[$columns, $values] = $sqlHelper->prepareInsert(self::getTableName(),
			[
				'MAILBOX_ID' => $mailboxId,
				'MESSAGE_ID' => $messageId,
				'CODE' => $code,
				'USER_ID' => $userId,
			]
		);

		$connection->queryExecute(
			$sqlHelper->getInsertIgnore(
				self::getTableName(),
				"($columns)",
				"VALUES($values)"
			)
		);
	}

	public static function deleteMark(int $mailboxId, int $messageId, int $code, int $userId): void
	{
		self::delete([
			'MAILBOX_ID' => $mailboxId,
			'MESSAGE_ID' => $messageId,
			'CODE' => $code,
			'USER_ID' => $userId,
		]);
	}

	/**
	 * Drops every mark of the given messages, whatever its kind and owner: MAILBOX_ID is part of the primary
	 * key, so no mark of a letter outlives the letter's presence in that mailbox.
	 *
	 * @param int[] $messageIds
	 */
	public static function deleteByMessages(int $mailboxId, array $messageIds): void
	{
		if ($messageIds === [])
		{
			// An empty list would render as "MESSAGE_ID IN ()" - a syntax error, not an empty result
			return;
		}

		foreach (array_chunk(array_map('intval', $messageIds), self::DELETE_CHUNK_SIZE) as $messageIdsChunk)
		{
			static::deleteByFilter([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIdsChunk,
			]);
		}
	}

	public static function getMap()
	{
		return array(
			'MAILBOX_ID' => array(
				'data_type' => 'integer',
				'required'  => true,
				'primary' => true,
			),
			'MESSAGE_ID' => array(
				'data_type' => 'integer',
				'required'  => true,
				'primary' => true,
			),
			'CODE' => array(
				'data_type' => 'integer',
				'required'  => true,
				'primary' => true,
			),
			'USER_ID' => array(
				'data_type' => 'integer',
				'required'  => true,
				'primary' => true,
			),
		);
	}
}
