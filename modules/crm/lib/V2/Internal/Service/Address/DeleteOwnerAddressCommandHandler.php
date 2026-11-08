<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Command\Address\DeleteOwnerAddressCommand;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * The scenario behind {@see DeleteOwnerAddressCommand}: the checking line of {@see OwnerAddressService}
 * removes the row together with the geodata behind it.
 *
 * Locating the row and the rights of its owner are answered by that operation, and a row it refuses to
 * show is a row that is not there.
 *
 * @internal
 */
final class DeleteOwnerAddressCommandHandler
{
	private readonly OwnerAddressService $ownerAddressService;

	public function __construct(?OwnerAddressService $ownerAddressService = null)
	{
		$this->ownerAddressService = $ownerAddressService ?? new OwnerAddressService();
	}

	/**
	 * @return Result A successful result carries no data.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user.
	 */
	public function handle(DeleteOwnerAddressCommand $command): Result
	{
		return $this->ownerAddressService->delete(
			$command->getAddressId(),
			$command->getOwnerTypeId(),
			$command->getOwnerId(),
			$command->getUserId(),
		);
	}
}
