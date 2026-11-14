<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\PortalAddressDto;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see PortalAddressDto} out of the rows {@see
 * \Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository::findAll()} reads. One direction only - the
 * method behind this contract reads, and there is no write path to map back into.
 *
 * A row becomes an answer by name: the read already speaks the field names this contract publishes, so
 * there is nothing to rename here. Neither is there anything to filter, to order or to cut into pages -
 * the query did all three, and doing any of it again would be doing it a second, disagreeing way.
 *
 * `select` is honoured twice over: the read loads the asked-for fields and nothing besides
 * ({@see AddressListRequestMapper::mapSelect()} resolves the list), and a property the client did not ask
 * for is never filled here and therefore stays out of the response entirely.
 *
 * The one field of the contract that is not a field of a row is {@see PortalAddressDto::$requisiteId}, and
 * folding it back is what this mapper adds to the plain copy: the storage answers the record the address
 * is kept on as a type and an identifier, and the identifier is published only where the record is a
 * requisite. The pair itself is published under no name at all.
 */
final class PortalAddressDtoMapper
{
	private const FIELD_REQUISITE_ID = 'requisiteId';

	private const FIELD_RECORD_TYPE_ID = 'recordTypeId';

	private const FIELD_RECORD_ID = 'recordId';

	/**
	 * @param array<int, array<string, mixed>> $rows field-keyed rows as the read answers them.
	 * @param string[] $selectedFieldNames {@see AddressListRequestMapper::mapSelect()}
	 */
	public function getDtoCollectionByRows(array $rows, array $selectedFieldNames): DtoCollection
	{
		$collection = new DtoCollection(PortalAddressDto::class);
		foreach ($rows as $row)
		{
			$collection->add(self::createDtoByRow($row, $selectedFieldNames));
		}

		return $collection;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[] $selectedFieldNames
	 */
	private static function createDtoByRow(array $row, array $selectedFieldNames): PortalAddressDto
	{
		/** @var PortalAddressDto $dto */
		$dto = PortalAddressDto::create();
		foreach ($selectedFieldNames as $fieldName)
		{
			if (!isset($dto->getFields()[$fieldName]))
			{
				continue;
			}

			$value = $fieldName === self::FIELD_REQUISITE_ID
				? self::requisiteIdOf($row)
				: $row[$fieldName] ?? null
			;
			if ($value === null)
			{
				continue;
			}

			// Writes into the field rather than into the property, so an unselected property stays
			// uninitialized and drops out of the response.
			$dto->__set($fieldName, $value);
		}

		return $dto;
	}

	/**
	 * The requisite the address is kept on, and zero when the record behind the row is something else -
	 * the same value the addresses of one owner have published all along.
	 *
	 * @param array<string, mixed> $row
	 * @return int|null `null` when the row carries no record at all, which means it was not asked for.
	 */
	private static function requisiteIdOf(array $row): ?int
	{
		if (!isset($row[self::FIELD_RECORD_TYPE_ID], $row[self::FIELD_RECORD_ID]))
		{
			return null;
		}

		return (int)$row[self::FIELD_RECORD_TYPE_ID] === OwnerType::REQUISITE
			? (int)$row[self::FIELD_RECORD_ID]
			: 0
		;
	}
}
