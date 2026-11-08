<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address\Handler;

use Bitrix\Crm\V2\Internal\Service\Address\EntityAddressService;
use Bitrix\Crm\V2\Public\Command\Address\DeleteAddressCommand;
use Bitrix\Main\Result;

/** @internal */
final class DeleteAddressCommandHandler
{
	private readonly EntityAddressService $entityAddressService;

	public function __construct(?EntityAddressService $entityAddressService = null)
	{
		$this->entityAddressService = $entityAddressService ?? new EntityAddressService();
	}

	public function handle(DeleteAddressCommand $command): Result
	{
		$this->entityAddressService->assertIdentifierBelongsToOwner($command->getIdentifier(), $command->getOwner());
		$this->entityAddressService->assertCanUpdate($command->getOwner(), $command->getUserId());
		$this->entityAddressService->delete($command->getIdentifier());

		return new Result();
	}
}
