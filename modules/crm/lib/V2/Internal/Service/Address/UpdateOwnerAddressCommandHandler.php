<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Command\Address\UpdateOwnerAddressCommand;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * The scenario behind {@see UpdateOwnerAddressCommand}: the fields the caller has touched become fields
 * of a row and the checking line of {@see OwnerAddressService} writes them onto the stored one.
 *
 * Only the touched fields are handed over. The operation of the internal layer merges what it is given
 * with what is stored, so a field left out of the change keeps its value, while a field passed as an
 * empty one blanks it. Locating the row, the rights of its owner and an address left empty by the change
 * are all answered by that operation - a row it refuses to show is a row that is not there.
 *
 * @internal
 */
final class UpdateOwnerAddressCommandHandler
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
	public function handle(UpdateOwnerAddressCommand $command): Result
	{
		return $this->ownerAddressService->update(
			$command->getAddressId(),
			$command->getOwnerTypeId(),
			$command->getOwnerId(),
			OwnerAddressFieldMap::toStoredChanges($command->getChanges()->getChangedFields()),
			$command->getUserId(),
		);
	}
}
