<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address\Handler;

use Bitrix\Crm\V2\Internal\Service\Address\EntityAddressService;
use Bitrix\Crm\V2\Public\Command\Address\SaveAddressCommand;
use Bitrix\Main\Result;

/** @internal */
final class SaveAddressCommandHandler
{
	private readonly EntityAddressService $entityAddressService;

	public function __construct(?EntityAddressService $entityAddressService = null)
	{
		$this->entityAddressService = $entityAddressService ?? new EntityAddressService();
	}

	public function handle(SaveAddressCommand $command): Result
	{
		$this->entityAddressService->assertIdentifierBelongsToOwner($command->getIdentifier(), $command->getOwner());
		$this->entityAddressService->assertCanUpdate($command->getOwner(), $command->getUserId());
		if ($command->getChanges()->getChangedFields() === [])
		{
			return new Result();
		}

		$this->entityAddressService->update(
			$command->getIdentifier(),
			$command->getOwner(),
			$command->getChanges(),
		);

		return new Result();
	}
}
