<?php

namespace Bitrix\Mail\Integration;

class Attachment
{
	public const MATERIALIZATION_READY = 'READY';
	public const MATERIALIZATION_NOT_READY = 'NOT_READY';

	public static function downloadAttachmentsByMessageId(int $messageId): bool
	{
		return static::materializeAttachmentsByMessageId($messageId) === static::MATERIALIZATION_READY;
	}

	public static function materializeAttachmentsByMessageId(int $messageId): string
	{
		$messageForDownload = static::selectMessageForDownload($messageId);
		if ($messageForDownload === null)
		{
			return static::MATERIALIZATION_NOT_READY;
		}

		return static::materializeSelectedAttachments($messageForDownload) !== null
			? static::MATERIALIZATION_READY
			: static::MATERIALIZATION_NOT_READY
		;
	}

	/**
	 * @return array<string, mixed>|null Hydrated local snapshot, or null when it cannot be made complete.
	 */
	public static function materializeAttachmentsForMessage(array $message): ?array
	{
		if (static::hasAllExpectedAttachments($message))
		{
			return $message;
		}

		$messageId = (int)($message['ID'] ?? 0);
		$messageForDownload = static::selectMessageForDownload($messageId, $message);
		if ($messageForDownload === null)
		{
			return null;
		}

		return static::materializeSelectedAttachments($messageForDownload);
	}

	protected static function materializeSelectedAttachments(array $messageForDownload): ?array
	{
		if (static::hasAllExpectedAttachments($messageForDownload))
		{
			return $messageForDownload;
		}

		$messageId = (int)($messageForDownload['ID'] ?? 0);
		$attachmentsBeforeDownload = (int)($messageForDownload['ATTACHMENTS'] ?? 0);
		$downloadResult = static::downloadAttachments($messageForDownload);
		$messageAfterDownload = static::selectStoredMessage($messageId);

		if ($messageAfterDownload !== null && static::hasAllExpectedAttachments($messageAfterDownload))
		{
			return $messageAfterDownload;
		}

		if (
			$downloadResult
			&& $messageAfterDownload !== null
			&& (int)($messageAfterDownload['ATTACHMENTS'] ?? 0) > $attachmentsBeforeDownload
		)
		{
			static::logPartialSave($messageId);
		}

		return null;
	}

	protected static function selectMessageForDownload(int $messageId, ?array $sourceMessage = null): ?array
	{
		$message = \Bitrix\Mail\MailMessageTable::getList([
			'runtime' => [
				new \Bitrix\Main\Entity\ReferenceField(
					'MESSAGE_UID',
					'Bitrix\Mail\MailMessageUidTable',
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					[
						'join_type' => 'INNER',
					]
				),
			],
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
				'MSG_HASH' => 'MESSAGE_UID.HEADER_MD5',
				'DIR_MD5' => 'MESSAGE_UID.DIR_MD5',
				'MSG_UID' => 'MESSAGE_UID.MSG_UID',
				// The generation of the placement: what tells a lazy download it is still usable
				'GENERATION_ID' => 'MESSAGE_UID.GENERATION_ID',
				// The version of the folder the uid was written under: the same download reads it too
				'DIR_UIDV' => 'MESSAGE_UID.DIR_UIDV',
			],
			'filter' => array_merge(
				['=ID' => $messageId],
				// Of the placements of the message, the download barrier only serves the active one
				\Bitrix\Mail\Helper\Message\Loader\QueryBuilder::generationScopeFilterOfMessages(
					[$messageId],
					'MESSAGE_UID.',
				),
			),
			'order' => [
				'FIELD_DATE' => 'DESC',
				'MESSAGE_UID.MSG_UID' => 'ASC',
			],
			'limit' => 1,
		])->fetch();

		return is_array($message) ? $message : null;
	}

	protected static function downloadAttachments(array &$message): bool
	{
		return (bool)\Bitrix\Mail\Helper\Message::ensureAttachments($message);
	}

	protected static function selectStoredMessage(int $messageId): ?array
	{
		$message = \Bitrix\Mail\MailMessageTable::getByPrimary($messageId, [
			'select' => ['*'],
		])->fetch();

		return is_array($message) ? $message : null;
	}

	protected static function hasAllExpectedAttachments(array $message): bool
	{
		$expectedAttachments = (int)($message['OPTIONS']['attachments'] ?? 0);
		$storedAttachments = (int)($message['ATTACHMENTS'] ?? 0);

		return $storedAttachments >= $expectedAttachments;
	}

	protected static function logPartialSave(int $messageId): void
	{
		addMessage2Log(
			sprintf('Integration\\Attachment: Attachment materialization saved partially (%u)', $messageId),
			'mail',
			2,
		);
	}
}
