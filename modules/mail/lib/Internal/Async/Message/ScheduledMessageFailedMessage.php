<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class ScheduledMessageFailedMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $scheduledMessageId,
		public readonly int $authorId,
		public readonly string $subject,
		public readonly string $errorCode,
		public readonly ?string $viewUrl = null,
	)
	{
	}
}
