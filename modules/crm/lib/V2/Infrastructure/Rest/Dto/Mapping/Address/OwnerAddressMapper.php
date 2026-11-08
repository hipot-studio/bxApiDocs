<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address;

use Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Converts between the DTO of an address route and the public {@see OwnerAddress}, in both directions.
 *
 * Two things about the route are needed for that and neither of them is a property of an address: the
 * contract of the route is a generated class
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator}), and the owner of an
 * address is published under the name of the entity of that route - `contactId` or `companyId`. Both are
 * given once, when the mapper is built, so that nothing downstream has to know which entity is being
 * served: an action reads the owner of a request through {@see self::getOwnerIdByDto()} rather than by
 * the name of a field.
 *
 * The type of the owner is not part of the contract at all. It is the trusted invariant of the route and
 * a client can neither read nor send it, which is why {@see self::mapToDto()} drops it: publishing it
 * would put a value into the answer that says nothing a client did not already know from the method it
 * called.
 *
 * ### A field that was not sent is not a field that was sent empty
 *
 * A write takes both the DTO and the keys the client actually sent, and only those keys reach the
 * address ({@see self::mapToChanges()}). This is the whole of what makes a partial write work: the
 * object that comes out carries changes for the sent fields alone
 * ({@see OwnerAddress::getChangedFields()}), and the write path puts exactly those over the stored
 * columns of the row, leaving everything else as it is stored
 * ({@see \Bitrix\Crm\V2\Internal\Service\Address\OwnerAddressService::update()}). Reading the DTO alone
 * would not do - a generated DTO answers `null` for a field nobody filled just as it does for a field
 * filled with nothing, and the two mean opposite things here.
 *
 * Keys that name no address field are skipped rather than refused: the fields of an `add` carry the type
 * of the address and its owner as well, and those reach the command as arguments of its own instead of
 * as changes to an address - an address never moves onto another owner or another type.
 */
final class OwnerAddressMapper
{
	/**
	 * @param string $ownerFieldName The name the owner of an address is published under, the same one the
	 *        route hands to the generator of its DTO.
	 * @param string $dtoClass The generated contract of the route, {@see \Bitrix\Rest\V3\Interaction\Request\Request::getDtoClass()}.
	 */
	public function __construct(
		private readonly string $ownerFieldName,
		private readonly string $dtoClass,
	)
	{
	}

	/**
	 * The address as the route answers it: every field of the contract filled.
	 *
	 * @throws ArgumentException When the contract of the route knows no field of the given owner name.
	 *         A DTO silently ignores a write to a field it does not declare, so a mapper and a contract
	 *         that disagree about the name would answer an address without an owner rather than fail.
	 */
	public function mapToDto(OwnerAddress $address): Dto
	{
		$values = $address->toArray();
		unset($values['ownerTypeId'], $values['ownerId']);
		$values[$this->ownerFieldName] = $address->getOwnerId();

		/** @var Dto $dto */
		$dto = $this->dtoClass::create();
		if (!isset($dto->getFields()[$this->ownerFieldName]))
		{
			throw new ArgumentException(
				"The address contract of this route publishes no field {$this->ownerFieldName}.",
			);
		}

		foreach ($values as $field => $value)
		{
			$dto->{$field} = $value;
		}

		return $dto;
	}

	/**
	 * The address fields of a write, as the commands of the group take them: an address carrying changes
	 * for the sent fields and nothing else.
	 *
	 * @param array<string, mixed> $fields The fields as the client sent them; only their keys are read,
	 *        the values come from the DTO the framework has already checked.
	 */
	public function mapToChanges(Dto $dto, array $fields): OwnerAddress
	{
		$address = new OwnerAddress();
		foreach (array_keys($fields) as $field)
		{
			match ($field)
			{
				'address1' => $address->setAddress1($dto->address1),
				'address2' => $address->setAddress2($dto->address2),
				'city' => $address->setCity($dto->city),
				'postalCode' => $address->setPostalCode($dto->postalCode),
				'region' => $address->setRegion($dto->region),
				'province' => $address->setProvince($dto->province),
				'country' => $address->setCountry($dto->country),
				'countryCode' => $address->setCountryCode($dto->countryCode),
				default => null,
			};
		}

		return $address;
	}

	/**
	 * The owner an address is added to; `0` when the client sent none, which the scenario answers as an
	 * owner that does not exist. The type of the address is read from the DTO by the action itself: its
	 * name is the same on every route of the group, while this one is not.
	 *
	 * Read out of the values of the DTO rather than off the property: a field nobody filled has no value
	 * at all, and asking one for it is an error rather than an empty answer.
	 */
	public function getOwnerIdByDto(Dto $dto): int
	{
		return (int)($dto->toArray(true)[$this->ownerFieldName] ?? 0);
	}
}
