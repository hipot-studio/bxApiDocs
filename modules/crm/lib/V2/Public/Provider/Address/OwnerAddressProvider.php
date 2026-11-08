<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Address;

use Bitrix\Crm\V2\Internal\Service\Address\OwnerAddressFieldMap;
use Bitrix\Crm\V2\Internal\Service\Address\OwnerAddressService;
use Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Provider\Params\PagerInterface;

/**
 * Reads the addresses of one contact or one company on behalf of one user.
 *
 * ```php
 * $addresses = (new OwnerAddressProvider())->getList(OwnerType::CONTACT, $contactId, $userId);
 * ```
 *
 * The owner is part of every call and so is the user: an address has no rights of its own and follows
 * the read right of the contact or the company it belongs to. An address of another owner is not read
 * through the owner given here even when the caller knows its identifier - the route is part of the
 * question.
 *
 * Reading one address by its identifier accepts the owner as a type with an identifier or as a type
 * alone - `0` in place of the identifier means any owner of that type, {@see self::get()}. Reading a list
 * always names a whole owner: a list is the addresses of somebody.
 *
 * A missing address, an address of another owner, an address of an owner the user may not see and an owner
 * that does not exist at all are one and the same answer, {@see ObjectNotFoundException}: telling them
 * apart would say whether a row or a record exists to somebody who may not know it.
 *
 * PAGING AND ORDER. The set of addresses of one owner is small - a few types of address over a few
 * requisites - and the permission filter of the storage already applies to it as a whole, so the type
 * filter, the order and the page are applied to what was read, the way {@see
 * \Bitrix\Crm\V2\Public\Provider\Category\CategoryProvider} does it. The order is always finished with
 * the identifier of the row, so two addresses that compare equal keep a stable place and neighbouring
 * pages hold no duplicates. How large a page may be is a decision of the caller: the provider takes the
 * page it is given.
 */
final class OwnerAddressProvider
{
	/** Fields {@see self::getList()} accepts an order over, by the names of {@see OwnerAddress::toArray()}. */
	private const SORTABLE_FIELDS = [
		'id' => 'ID',
		'typeId' => 'TYPE_ID',
	];

	private ?OwnerAddressService $ownerAddressService = null;

	/**
	 * One address of the owner.
	 *
	 * @param int $ownerId Identifier of the owner, `0` for any owner of `$ownerTypeId`. The identifier
	 *        narrows the question and never widens it: whichever owner the address turns out to belong to,
	 *        the read right of that owner is what decides the answer, so an address of an owner the user
	 *        may not see is not there either way.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user, the named
	 *         owner included: an owner that does not exist has no address to read.
	 */
	public function get(int $addressId, int $ownerTypeId, int $ownerId, int $userId): OwnerAddress
	{
		return self::toOwnerAddress(
			$this->getOwnerAddressService()->get($addressId, $ownerTypeId, $ownerId, $userId),
		);
	}

	/**
	 * The addresses of the owner the user may read.
	 *
	 * @param int|null $typeId Type of address to read, `null` for every type.
	 * @param array<string, string> $order `field => 'ASC'|'DESC'` over {@see self::SORTABLE_FIELDS};
	 *        the identifier of the row finishes it whenever the caller has not named it.
	 * @param PagerInterface|null $pager `null` for the whole set.
	 * @return list<OwnerAddress>
	 * @throws ArgumentException When the order names a field there is no order over.
	 * @throws ObjectNotFoundException When the owner does not exist or the user may not read it.
	 */
	public function getList(
		int $ownerTypeId,
		int $ownerId,
		int $userId,
		?int $typeId = null,
		array $order = [],
		?PagerInterface $pager = null,
	): array
	{
		$sorting = self::buildSorting($order);
		$rows = $this->getOwnerAddressService()->getAllByOwner($ownerTypeId, $ownerId, $userId);

		if ($typeId !== null)
		{
			$rows = array_values(array_filter(
				$rows,
				static fn(array $row): bool => (int)$row['TYPE_ID'] === $typeId,
			));
		}

		usort($rows, static fn(array $left, array $right): int => self::compareRows($left, $right, $sorting));

		if ($pager !== null)
		{
			$rows = array_slice($rows, $pager->getOffset(), $pager->getLimit());
		}

		return array_map(self::toOwnerAddress(...), $rows);
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string> `column => 'ASC'|'DESC'`, the identifier of the row last.
	 * @throws ArgumentException
	 */
	private static function buildSorting(array $order): array
	{
		$sorting = [];
		foreach ($order as $field => $direction)
		{
			if (!isset(self::SORTABLE_FIELDS[$field]))
			{
				throw new ArgumentException("There is no order over an address by {$field}.", 'order');
			}

			$sorting[self::SORTABLE_FIELDS[$field]] = strcasecmp($direction, 'DESC') === 0 ? 'DESC' : 'ASC';
		}

		$sorting[self::SORTABLE_FIELDS['id']] ??= 'ASC';

		return $sorting;
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @param array<string, string> $sorting
	 */
	private static function compareRows(array $left, array $right, array $sorting): int
	{
		foreach ($sorting as $column => $direction)
		{
			$comparison = $left[$column] <=> $right[$column];
			if ($comparison !== 0)
			{
				return $direction === 'DESC' ? -$comparison : $comparison;
			}
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $row Row as the internal layer reads it.
	 */
	private static function toOwnerAddress(array $row): OwnerAddress
	{
		$values = [
			'id' => (int)$row['ID'],
			'typeId' => (int)$row['TYPE_ID'],
			// A row of an owner lives on a requisite of that owner; one bound to the owner itself has
			// no requisite behind it at all.
			'requisiteId' => (int)$row['ENTITY_TYPE_ID'] === OwnerType::REQUISITE ? (int)$row['ENTITY_ID'] : 0,
			'ownerTypeId' => (int)$row['ANCHOR_TYPE_ID'],
			'ownerId' => (int)$row['ANCHOR_ID'],
		];

		return OwnerAddress::fromArray(array_merge($values, OwnerAddressFieldMap::toAddressValues($row)));
	}

	private function getOwnerAddressService(): OwnerAddressService
	{
		return $this->ownerAddressService ??= new OwnerAddressService();
	}
}
