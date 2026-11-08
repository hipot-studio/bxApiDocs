<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Mailbox\Alias;

final class AddressOccupation
{
	public function __construct(
		public readonly AddressOccupationStatus $status,
		public readonly ?string $normalizedEmail,
		public readonly array $mailboxIds = [],
		public readonly array $aliasIds = [],
	)
	{
	}

	public function isOccupied(): bool
	{
		return $this->status !== AddressOccupationStatus::Free;
	}
}
