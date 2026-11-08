<?php

namespace Bitrix\Mail\Helper\Dto;

use Bitrix\Mail;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Message\Loader\MessageLoader;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\EntitySelector\ItemCollection;

class MailMessage
{
	public const DIRECTION_UNDEFINED = 0;
	public const DIRECTION_INCOMING = 1;
	public const DIRECTION_OUTGOING = 2;

	public int $id;
	public int $key;
	public string $uidId;

	public ItemCollection $from;
	public ItemCollection $to;
	public ItemCollection $cc;
	public ItemCollection $bcc;
	public ItemCollection $replyTo;

	/**
	 * This is necessary to restrict the sending of messages to employees when responding to a message
	 */
	public ItemCollection $employees;

	/**
	 * Mailboxes of employees from which you can answer this message
	 */
	public ItemCollection $availableSenders;

	public string $subject;
	public int $date;
	public bool $isRead = false;
	public bool $isFavorite = false;
	public bool $isFavoriteAllowed = false;
	public int $ownerTypeId = 0;
	public int $ownerId = 0;
	public int $crmBindId = 0;
	public int $chatBindId = 0;
	public int $taskBindId = 0;
	public int $eventBindId = 0;
	public int $blogPostBindId = 0;
	public int $crmBindTypeId = 0;
	public string $ownerType;
	public int $direction;
	public bool $readOnly = false;
	public ?string $body = '';
	public bool $isBodyThemed = false;
	public int $withAttachments = 0;
	public array $attachments = [];
	public string $abbreviatedText;
	public string $replyFromEmail = '';
	public ?int $mailboxId = null;
	public ?int $folderId = null;

	public static function fromListRow(
		array $row,
		?int $folderId = null,
		bool $hideReadStatus = false,
	): self
	{
		$message = new self();
		$message->id = (int)$row['MESSAGE_ID'];
		$message->uidId = (string)$row['UID_ID'] . '-' . (int)$row['MAILBOX_ID'];
		$message->mailboxId = (int)$row['MAILBOX_ID'];
		$message->folderId = $folderId;
		$message->subject = (string)($row['SUBJECT'] ?? '');
		$message->abbreviatedText = self::abbreviateText(
			Message::getDisplaySnippet(
				(string)($row['BODY_PREVIEW'] ?? $row['BODY'] ?? ''),
				$message->subject,
			),
		);
		$message->date = self::readDate($row);
		$message->withAttachments = (int)($row['OPTIONS']['attachments'] ?? $row['ATTACHMENTS'] ?? 0);
		$message->isFavorite = (bool)($row['__is_favorite'] ?? false);
		$message->isFavoriteAllowed = array_key_exists('__is_favorite', $row);
		self::fillListRecipients($message, $row);
		$message->mergeListRow($row, $hideReadStatus);

		return $message;
	}

	public function mergeListRow(array $row, bool $hideReadStatus = false): void
	{
		if (in_array((string)($row['IS_SEEN'] ?? ''), ['Y', 'S'], true) || $hideReadStatus)
		{
			$this->isRead = true;
		}
	}

	public function toArray(): array
	{
		return get_object_vars($this);
	}

	private static function readDate(array $row): int
	{
		$date = $row['INTERNALDATE'] ?? $row['FIELD_DATE'] ?? null;

		return $date instanceof DateTime ? $date->getTimestamp() : 0;
	}

	private static function fillListRecipients(self $message, array $row): void
	{
		$row = Message::restoreAddressFieldsFromHeader($row);

		$from = self::buildRecipientItems((string)($row['FIELD_FROM'] ?? ''));
		$replyTo = self::buildRecipientItems((string)($row['FIELD_REPLY_TO'] ?? ''));

		$message->bcc = Mail\Message::getSelectedRecipientsForDialog(
			self::buildRecipientItems((string)($row['FIELD_BCC'] ?? '')),
		);
		$message->cc = Mail\Message::getSelectedRecipientsForDialog(
			self::buildRecipientItems((string)($row['FIELD_CC'] ?? '')),
		);
		$message->to = Mail\Message::getSelectedRecipientsForDialog(
			self::buildRecipientItems((string)($row['FIELD_TO'] ?? '')),
		);
		$message->from = Mail\Message::getSelectedRecipientsForDialog($from);
		$message->replyTo = Mail\Message::getSelectedRecipientsForDialog($replyTo === [] ? $from : $replyTo);
		$message->direction = self::isOutgoing($row, array_merge($from, $replyTo))
			? self::DIRECTION_OUTGOING
			: self::DIRECTION_INCOMING;
	}

	/** @return list<array{name: string, email: string, entityId: null}> */
	private static function buildRecipientItems(string $field): array
	{
		return array_map(
			static fn($contact): array => [
				'name' => (string)$contact->name,
				'email' => (string)$contact->email,
				'entityId' => null,
			],
			MessageLoader::buildContactList($field),
		);
	}

	/** @param list<array{email: string}> $senders */
	private static function isOutgoing(array $row, array $senders): bool
	{
		$mailboxEmails = [];
		foreach (['MAILBOX_EMAIL', 'MAILBOX_NAME', 'MAILBOX_LOGIN'] as $field)
		{
			foreach (MessageLoader::buildContactList((string)($row[$field] ?? '')) as $contact)
			{
				$mailboxEmails[mb_strtolower((string)$contact->email)] = true;
			}
		}

		foreach ($senders as $sender)
		{
			if (isset($mailboxEmails[mb_strtolower($sender['email'])]))
			{
				return true;
			}
		}

		return false;
	}

	private static function abbreviateText(string $text): string
	{
		$text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', mb_substr($text, 0, 50));

		while (str_contains($text, '  '))
		{
			$text = str_replace('  ', ' ', $text);
		}

		return trim($text);
	}
}
