<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\V2\Public\Command\Address\AddOwnerAddressCommand;
use Bitrix\Crm\V2\Public\Command\Address\OwnerAddressError;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * The scenario behind {@see AddOwnerAddressCommand}: the values of the public address become fields of a
 * row and the checking line of {@see OwnerAddressService} writes them, rights included.
 *
 * The whole address is written and not only the fields the caller has touched: adding stores an address
 * as it was given, and a field nobody filled is stored as no value rather than left to a previous state
 * there is none of.
 *
 * The type of the address is answered here and not by the service: a type outside the dictionary is
 * refused to a caller of the public command, while the service keeps writing whatever the legacy address
 * class hands it - the old entry point never checked the type, and checking it there would change what
 * conversion, import and web forms do today.
 *
 * @internal
 */
final class AddOwnerAddressCommandHandler
{
	private readonly OwnerAddressService $ownerAddressService;

	public function __construct(?OwnerAddressService $ownerAddressService = null)
	{
		$this->ownerAddressService = $ownerAddressService ?? new OwnerAddressService();
	}

	/**
	 * @return Result Data of a successful result:
	 *         `[AddOwnerAddressCommand::DATA_ADDRESS_ID => int]`. An unsuccessful result carries a domain
	 *         error of {@see OwnerAddressError}.
	 * @throws ObjectNotFoundException When the owner does not exist or the user may not change it.
	 */
	public function handle(AddOwnerAddressCommand $command): Result
	{
		if (!EntityAddressType::isDefined($command->getTypeId()))
		{
			return (new Result())->addError(new Error(
				'The address type dictionary describes no type of this identifier.',
				OwnerAddressError::ADDRESS_TYPE_UNKNOWN->value,
			));
		}

		$result = $this->ownerAddressService->add(
			$command->getOwnerTypeId(),
			$command->getOwnerId(),
			$command->getTypeId(),
			OwnerAddressFieldMap::toStoredFields($command->getAddress()->toArray()),
			$command->getUserId(),
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return (new Result())->setData([
			AddOwnerAddressCommand::DATA_ADDRESS_ID => (int)$result->getData()[OwnerAddressService::ADDRESS_ID],
		]);
	}
}
