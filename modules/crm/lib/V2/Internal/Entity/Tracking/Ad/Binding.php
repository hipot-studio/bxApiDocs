<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking\Ad;

final readonly class Binding
{
	public function __construct(
		public int $sourceId,
		public string $code,
		public string $provider,
		public string $clientId,
		public ?string $accountId,
		public bool $active,
	)
	{
	}

	public function equals(self $other): bool
	{
		return $this->sourceId === $other->sourceId
			&& $this->code === $other->code
			&& $this->provider === $other->provider
			&& $this->clientId === $other->clientId
			&& $this->accountId === $other->accountId
			&& $this->active === $other->active
		;
	}
}
