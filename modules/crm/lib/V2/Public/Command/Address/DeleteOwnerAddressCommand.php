<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\DeleteOwnerAddressCommandHandler;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Deletes one stored address of a contact or a company on behalf of one user.
 *
 * ```php
 * $result = (new DeleteOwnerAddressCommand($addressId, OwnerType::CONTACT, $contactId, $userId))->run();
 * ```
 *
 * The address is named the same way a change names it - by the identifier of the row together with the
 * owner it belongs to, whose identifier may be left out as `0` for any owner of the given type - and a
 * missing row, a row of another owner, a row of an owner the user may not change and a named owner that
 * does not exist leave through the same {@see ObjectNotFoundException}, so a repeated deletion of the same
 * address is refused as a missing one rather than reported as done twice.
 *
 * Only the address goes away. The requisite it was stored on stays, and so does the owner; the addresses
 * left to the owner keep their types, and none of them becomes the deleted one instead - the command
 * removes an address, it does not choose another.
 */
final class DeleteOwnerAddressCommand implements CommandInterface
{
	/**
	 * @param int $ownerTypeId Type of the CRM owner, {@see OwnerType::CONTACT} or {@see OwnerType::COMPANY}.
	 * @param int $ownerId Identifier of the owner, `0` for any owner of `$ownerTypeId`.
	 */
	public function __construct(
		private readonly int $addressId,
		private readonly int $ownerTypeId,
		private readonly int $ownerId,
		private readonly int $userId,
	)
	{
	}

	public function getAddressId(): int
	{
		return $this->addressId;
	}

	public function getOwnerTypeId(): int
	{
		return $this->ownerTypeId;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * @return Result A successful result carries no data.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user.
	 */
	public function run(): Result
	{
		return (new DeleteOwnerAddressCommandHandler())->handle($this);
	}
}
