<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Address;

final readonly class AddressIdentifier
{
	public function __construct(
		public int $recordTypeId,
		public int $recordId,
		public int $typeId,
	)
	{
	}

	public function getRecordTypeId(): int
	{
		return $this->recordTypeId;
	}

	public function getRecordId(): int
	{
		return $this->recordId;
	}

	public function getTypeId(): int
	{
		return $this->typeId;
	}
}
