<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address\Requisite;

use Bitrix\Crm\V2\Internal\Service\Address\RequisiteAddressService;
use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

final class UpdateCommand implements CommandInterface
{
	public function __construct(
		private readonly int $requisiteId,
		private readonly int $typeId,
		private readonly int $ownerTypeId,
		private readonly Address $changes,
		private readonly int $userId,
	)
	{
	}

	public function getRequisiteId(): int
	{
		return $this->requisiteId;
	}

	public function getTypeId(): int
	{
		return $this->typeId;
	}

	public function getOwnerTypeId(): int
	{
		return $this->ownerTypeId;
	}

	public function getChanges(): Address
	{
		return $this->changes;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function run(): Result
	{
		(new RequisiteAddressService())->update(
			$this->requisiteId,
			$this->typeId,
			$this->ownerTypeId,
			$this->changes,
			$this->userId,
		);

		return new Result();
	}
}
