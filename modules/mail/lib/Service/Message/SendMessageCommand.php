<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Message;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Main\Mail\Sender\Identity;

final class SendMessageCommand
{
	/**
	 * @param string[] $recipients
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @param array<int, array<string, mixed>> $attachments
	 * @param int[] $externalLinkIds
	 */
	public function __construct(
		public readonly int $authorId,
		public readonly ?Mailbox $mailbox,
		public readonly string $sender,
		public readonly array $recipients,
		public readonly array $cc,
		public readonly array $bcc,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $attachments,
		public readonly string $messageId,
		public readonly ?string $inReplyTo = null,
		public readonly array $externalLinkIds = [],
		public readonly ?Identity $senderIdentity = null,
	)
	{
	}
}
