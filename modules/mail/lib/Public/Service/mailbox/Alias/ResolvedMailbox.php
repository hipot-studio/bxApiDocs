<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Mailbox\Alias;

final class ResolvedMailbox
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly string $currentEmail,
		public readonly bool $isAlias,
	)
	{
	}
}
