<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto;

/**
 * A batch of messages belonging to a single mailbox.
 * Used to split composite IDs ("{messageId}-{mailboxId}")
 * when performing bulk operations across multiple mailboxes.
 */
final class MailboxMessageBatch
{
	public function __construct(
		public readonly int $mailboxId,
		/** @var string[] Composite IDs in "{messageId}-{mailboxId}" format, e.g. ["123-1", "456-1"] */
		public readonly array $compositeIds,
		/** @var string[] MailMessageUidTable record IDs, e.g. ["123", "456"] */
		public readonly array $messageIds,
	)
	{
	}
}