<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Address;

final readonly class AddressOwner
{
	public function __construct(
		public int $entityTypeId,
		public int $entityId,
	)
	{
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}
}
