<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

final class DraftCriteria
{
	public function __construct(
		public readonly string $search = '',
		public readonly string $recipient = '',
		public readonly ?bool $hasAttachments = null,
	)
	{
	}
}
