<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\UpdateOwnerAddressCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Changes the fields of one stored address of a contact or a company on behalf of one user.
 *
 * ```php
 * $result = (new UpdateOwnerAddressCommand(
 *     $addressId,
 *     OwnerType::CONTACT,
 *     $contactId,
 *     (new OwnerAddress())->setCity('Saint Petersburg'),
 *     $userId,
 * ))->run();
 * ```
 *
 * The address to change is named by the command and not by the object: the identifier of the row and the
 * owner it belongs to are arguments, while the {@see OwnerAddress} carries the fields the caller has
 * touched. Only those fields are written - {@see OwnerAddress::getChangedFields()} answers with them -
 * and a field nobody touched keeps whatever is stored. Setting a field to an empty value is a change like
 * any other and blanks that field.
 *
 * Neither the type of the address nor the requisite it is stored on is part of a change: an address stays
 * where it was written. Moving an address to another type is adding one there and deleting this one.
 *
 * A change that writes reaches another module as well: the fields of the address and the address of the
 * location module they are shown through are two views of one value and are written together. A stored
 * address that has no geodata yet gets an address of the location module created for it on the first
 * change of its fields, in the default language of the portal.
 *
 * A change that asks for the values the address already holds writes nothing at all, geodata included. An
 * address whose two views have drifted apart is therefore not brought back together by writing its stored
 * values again: only a change of the fields themselves rewrites the address of the location module.
 *
 * The owner is part of the call for the same reason it is part of a read: an address of another owner is
 * not changed through the owner given here even when the caller knows its identifier. Its identifier may
 * be left out - `0` means any owner of the given type - and then the owner of the row itself is the one
 * whose right to change decides the call. A missing row, a row of another owner, a row of an owner the
 * user may not change and a named owner that does not exist are one and the same answer,
 * {@see ObjectNotFoundException} - telling them apart would say whether a row exists to somebody who may
 * not know it.
 *
 * An address left without a value in every one of its fields is refused as an error of the result
 * ({@see OwnerAddressError::ADDRESS_IS_EMPTY}) and not as an exception: blanking an address is deleting
 * it, and deleting is a command of its own ({@see DeleteOwnerAddressCommand}).
 *
 * The code of the country is refused the same way ({@see OwnerAddressError::ADDRESS_COUNTRY_CODE_MISMATCH})
 * when it does not hold up against the country of the address. The address answered for is the one the row
 * would hold after the change, so a change naming the code alone is refused or accepted by the country
 * that is already stored, and a change that asks for the values the row already holds is not refused at
 * all - it writes nothing.
 */
final class UpdateOwnerAddressCommand implements CommandInterface
{
	/**
	 * @param int $ownerTypeId Type of the CRM owner, {@see OwnerType::CONTACT} or {@see OwnerType::COMPANY}.
	 * @param int $ownerId Identifier of the owner, `0` for any owner of `$ownerTypeId`.
	 * @param OwnerAddress $changes Address fields to write; the ones nobody touched are left as stored.
	 */
	public function __construct(
		private readonly int $addressId,
		private readonly int $ownerTypeId,
		private readonly int $ownerId,
		private readonly OwnerAddress $changes,
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

	public function getChanges(): OwnerAddress
	{
		return $this->changes;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * @return Result A successful result carries no data. An unsuccessful one carries a domain error.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user.
	 */
	public function run(): Result
	{
		return (new UpdateOwnerAddressCommandHandler())->handle($this);
	}
}
