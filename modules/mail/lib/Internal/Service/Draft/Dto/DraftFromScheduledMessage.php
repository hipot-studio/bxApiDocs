<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Draft\Dto;

final class DraftFromScheduledMessage
{
	public function __construct(
		public readonly int $userId,
		public readonly int $scheduledMessageId,
		public readonly ?array $sender,
		public readonly array $recipients,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $attachments,
		public readonly array $largeAttachments = [],
		public readonly string $mode = 'new',
		public readonly ?int $parentMessageId = null,
	)
	{
	}
}
