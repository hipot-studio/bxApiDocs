<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\AddOwnerAddressCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Adds an address to a contact or a company on behalf of one user.
 *
 * ```php
 * $result = (new AddOwnerAddressCommand(
 *     OwnerType::CONTACT,
 *     $contactId,
 *     $typeId,
 *     (new OwnerAddress())->setAddress1('Nevsky 1')->setCity('Saint Petersburg'),
 *     $userId,
 * ))->run();
 * $addressId = $result->isSuccess() ? $result->getData()[AddOwnerAddressCommand::DATA_ADDRESS_ID] : 0;
 * ```
 *
 * The command names the address it writes: the owner and the type of the address are arguments of the
 * command, and the {@see OwnerAddress} carries the values of the address fields alone. An identity the
 * object may carry - it has one when it was read rather than built - is not read here: adding an address
 * never moves a stored one onto another owner or another type. The type is a value of the address type
 * dictionary of CRM, the one {@see \Bitrix\Crm\V2\Public\Provider\Dictionary\AddressTypeProvider} reads;
 * a number that dictionary does not describe names no type to store the address as and is refused
 * ({@see OwnerAddressError::ADDRESS_TYPE_UNKNOWN}) before anything of the owner is touched.
 *
 * The owner keeps its addresses on its requisites, so adding one is more than a row: it may create a
 * requisite of the owner and may move the default one. The right to do that is the right to change the
 * owner, and an owner the user may not change is answered exactly as an owner that does not exist,
 * {@see ObjectNotFoundException} - telling the two apart would say whether the record exists to somebody
 * who may not know it.
 *
 * Everything else the operation can refuse is an error of the result and not an exception: an address
 * without a single filled field ({@see OwnerAddressError::ADDRESS_IS_EMPTY}), an address of a type the
 * dictionary does not describe ({@see OwnerAddressError::ADDRESS_TYPE_UNKNOWN}), an address whose code of
 * the country does not hold up against the country beside it
 * ({@see OwnerAddressError::ADDRESS_COUNTRY_CODE_MISMATCH}), an address of a type the target requisite
 * already holds ({@see OwnerAddressError::ADDRESS_TYPE_OCCUPIED}) and an owner left without a requisite to
 * store the address on.
 */
final class AddOwnerAddressCommand implements CommandInterface
{
	/** Result data key - identifier of the stored address, int. */
	public const DATA_ADDRESS_ID = 'addressId';

	/**
	 * @param int $ownerTypeId Type of the CRM owner, {@see OwnerType::CONTACT} or {@see OwnerType::COMPANY}.
	 * @param OwnerAddress $address Values of the address fields; its identity is not read.
	 */
	public function __construct(
		private readonly int $ownerTypeId,
		private readonly int $ownerId,
		private readonly int $typeId,
		private readonly OwnerAddress $address,
		private readonly int $userId,
	)
	{
	}

	public function getOwnerTypeId(): int
	{
		return $this->ownerTypeId;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function getTypeId(): int
	{
		return $this->typeId;
	}

	public function getAddress(): OwnerAddress
	{
		return $this->address;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * @return Result Data of a successful result: `[self::DATA_ADDRESS_ID => int]`.
	 * @throws ObjectNotFoundException When the owner does not exist or the user may not change it.
	 */
	public function run(): Result
	{
		return (new AddOwnerAddressCommandHandler())->handle($this);
	}
}
