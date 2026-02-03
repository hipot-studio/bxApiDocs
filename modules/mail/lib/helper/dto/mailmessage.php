<?php

namespace Bitrix\Mail\Helper\Dto;

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
	public int $ownerTypeId = 0;
	public int $ownerId = 0;
	public int $crmBindId = 0;
	public int $chatBindId = 0;
	public int $taskBindId = 0;
	public int $crmBindTypeId = 0;
	public string $ownerType;
	public int $direction;
	public ?string $body = '';
	public int $withAttachments = 0;
	public array $attachments = [];
	public string $abbreviatedText;
	public string $replyFromEmail = '';
}