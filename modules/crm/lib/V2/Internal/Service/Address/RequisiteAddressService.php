<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\RequisiteAddress;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\Item\CompanyRepository;
use Bitrix\Crm\V2\Internal\Repository\Item\ContactRepository;
use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Exception\EntityAlreadyExistsException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

class RequisiteAddressService
{
	private const OWNER_REPOSITORY_BY_TYPE = [
		\CCrmOwnerType::Contact => ContactRepository::class,
		\CCrmOwnerType::Company => CompanyRepository::class,
	];

	public function __construct(
		private readonly AddressStorage $storage = new AddressStorage(),
	)
	{
	}

	public function get(int $requisiteId, int $typeId, int $ownerTypeId, int $userId): Address
	{
		$this->assertAddressTypeIsAvailable($typeId);
		$requisite = $this->getAvailableRequisite($requisiteId, $ownerTypeId, $userId, 'read');
		$address = $this->getAvailableAddress($requisiteId, $typeId, $requisite);

		return $this->createAddress($address);
	}

	/**
	 * @return array<int, Address>
	 */
	public function getList(
		int $requisiteId,
		int $ownerTypeId,
		int $userId,
		array $filter = [],
		array $order = [],
		?int $limit = null,
		?int $offset = null,
	): array
	{
		$requisite = $this->getAvailableRequisite($requisiteId, $ownerTypeId, $userId, 'read');
		$addresses = [];
		foreach ($this->getAddressesByRequisite($requisiteId) as $address)
		{
			if ($this->isAddressAnchoredToRequisite($address, $requisite))
			{
				$addresses[(int)$address['TYPE_ID']] = $this->createAddress($address);
			}
		}
		$addresses = $this->filterAddresses($addresses, $filter);
		$this->sortAddresses($addresses, $order);
		if ($limit !== null)
		{
			$addresses = array_slice($addresses, $offset ?? 0, $limit, true);
		}

		return $addresses;
	}

	public function add(int $requisiteId, int $typeId, int $ownerTypeId, Address $address, int $userId): void
	{
		$this->assertAddressTypeIsAvailable($typeId);
		$requisite = $this->getAvailableRequisite($requisiteId, $ownerTypeId, $userId, 'update');
		if ($this->storage->exists($this->createIdentifier($requisiteId, $typeId)))
		{
			throw new EntityAlreadyExistsException((string)$typeId);
		}
		$this->assertAddressIsNotEmpty($address);
		$this->assertCountryCodeIsConsistent($address);
		$this->save($requisiteId, $typeId, $requisite, $address);
	}

	public function update(int $requisiteId, int $typeId, int $ownerTypeId, Address $changes, int $userId): void
	{
		$this->assertAddressTypeIsAvailable($typeId);
		$requisite = $this->getAvailableRequisite($requisiteId, $ownerTypeId, $userId, 'update');
		$current = $this->getAvailableAddress($requisiteId, $typeId, $requisite);
		$currentAddress = $this->createAddress($current);
		$address = $currentAddress->applyChanges($changes);
		$this->assertAddressIsNotEmpty($address);
		if ($currentAddress->toArray() === $address->toArray())
		{
			return;
		}
		$this->assertCountryCodeIsConsistent($address);
		$this->save($requisiteId, $typeId, $requisite, $address);
	}

	public function delete(int $requisiteId, int $typeId, int $ownerTypeId, int $userId): void
	{
		$this->assertAddressTypeIsAvailable($typeId);
		$requisite = $this->getAvailableRequisite($requisiteId, $ownerTypeId, $userId, 'delete');
		$this->getAvailableAddress($requisiteId, $typeId, $requisite);

		RequisiteAddress::unregister(\CCrmOwnerType::Requisite, $requisiteId, $typeId);
	}

	private function getAvailableRequisite(int $requisiteId, int $ownerTypeId, int $userId, string $operation): array
	{
		if ($requisiteId <= 0 || !in_array($ownerTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true))
		{
			throw new EntityNotFoundException($requisiteId);
		}

		$requisite = RequisiteTable::getRow([
			'filter' => ['=ID' => $requisiteId],
			'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
		]);
		if (
			!is_array($requisite)
			|| (int)$requisite['ENTITY_TYPE_ID'] !== $ownerTypeId
			|| (int)$requisite['ENTITY_ID'] <= 0
			|| !$this->doesOwnerExist($ownerTypeId, (int)$requisite['ENTITY_ID'])
			|| !$this->hasPermission($operation, $ownerTypeId, (int)$requisite['ENTITY_ID'], $userId)
		)
		{
			throw new EntityNotFoundException($requisiteId);
		}

		return $requisite;
	}

	private function doesOwnerExist(int $ownerTypeId, int $ownerId): bool
	{
		$repositoryClass = self::OWNER_REPOSITORY_BY_TYPE[$ownerTypeId] ?? null;

		return $repositoryClass !== null && (new $repositoryClass())->getById($ownerId, ['ID']) !== null;
	}

	private function hasPermission(string $operation, int $ownerTypeId, int $ownerId, int $userId): bool
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		if ($ownerTypeId === \CCrmOwnerType::Company && \CCrmCompany::isMyCompany($ownerId))
		{
			return match ($operation) {
				'read' => $userPermissions->myCompany()->canReadBaseFields($ownerId),
				'update' => $userPermissions->myCompany()->canUpdate(),
				'delete' => $userPermissions->myCompany()->canDelete(),
			};
		}

		$permissions = $userPermissions->item();

		return match ($operation) {
			'read' => $permissions->canRead($ownerTypeId, $ownerId),
			'update' => $permissions->canUpdate($ownerTypeId, $ownerId),
			'delete' => $permissions->canDelete($ownerTypeId, $ownerId),
		};
	}

	private function assertAddressTypeIsAvailable(int $typeId): void
	{
		if ($typeId <= 0 || !in_array($typeId, EntityAddressType::getAvailableIds(), true))
		{
			throw new ArgumentException('Address type is not available in the current portal zone.', 'typeId');
		}
	}

	private function assertAddressIsNotEmpty(Address $address): void
	{
		$values = $address->toArray();
		foreach (['address1', 'address2', 'city', 'postalCode', 'region', 'province', 'country'] as $field)
		{
			if ($values[$field] !== '')
			{
				return;
			}
		}

		throw new ArgumentException('At least one address field must not be empty.', 'address');
	}

	private function assertCountryCodeIsConsistent(Address $address): void
	{
		$addressFields = $this->toEntityAddressFields($address);
		$countryCode = $addressFields['COUNTRY_CODE'];
		if (
			($countryCode !== '' && $this->getCountryByCode($countryCode) === null)
			|| ($addressFields['COUNTRY'] !== '' && CountryCodeConsistency::isRefused($addressFields))
		)
		{
			throw new ArgumentException(CountryCodeConsistency::MISMATCH_MESSAGE, 'countryCode');
		}
	}

	protected function getCountryByCode(string $countryCode): ?array
	{
		$country = EntityAddress::getCountryByCode($countryCode);

		return is_array($country) ? $country : null;
	}

	private function getAvailableAddress(int $requisiteId, int $typeId, array $requisite): array
	{
		$address = $this->storage->findRow($this->createIdentifier($requisiteId, $typeId));
		if ($address === null || !$this->isAddressAnchoredToRequisite($address, $requisite))
		{
			throw new EntityNotFoundException($requisiteId);
		}

		return $address;
	}

	private function isAddressAnchoredToRequisite(array $address, array $requisite): bool
	{
		return (int)($address['ANCHOR_TYPE_ID'] ?? 0) === (int)$requisite['ENTITY_TYPE_ID']
			&& (int)($address['ANCHOR_ID'] ?? 0) === (int)$requisite['ENTITY_ID'];
	}

	/** @return array<int, array<string, mixed>> */
	private function getAddressesByRequisite(int $requisiteId): array
	{
		return $this->storage->findRowsByRecord(\CCrmOwnerType::Requisite, $requisiteId);
	}

	private function createIdentifier(
		int $requisiteId,
		int $typeId,
	): \Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier
	{
		return new \Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier(
			\CCrmOwnerType::Requisite,
			$requisiteId,
			$typeId,
		);
	}

	private function save(int $requisiteId, int $typeId, array $requisite, Address $address): void
	{
		$owner = new AddressOwner((int)$requisite['ENTITY_TYPE_ID'], (int)$requisite['ENTITY_ID']);
		$addressFields = $this->toEntityAddressFields($address);
		if ($addressFields['COUNTRY'] === '' && $addressFields['COUNTRY_CODE'] !== '')
		{
			$addressFields['COUNTRY'] = (string)$this->getCountryByCode($addressFields['COUNTRY_CODE'])['CAPTION'];
		}

		RequisiteAddress::register(\CCrmOwnerType::Requisite, $requisiteId, $typeId, $addressFields + [
			'ANCHOR_TYPE_ID' => $owner->getEntityTypeId(),
			'ANCHOR_ID' => $owner->getEntityId(),
		]);
	}

	private function toEntityAddressFields(Address $address): array
	{
		$values = $address->toArray();

		return [
			'ADDRESS_1' => $values['address1'],
			'ADDRESS_2' => $values['address2'],
			'CITY' => $values['city'],
			'POSTAL_CODE' => $values['postalCode'],
			'REGION' => $values['region'],
			'PROVINCE' => $values['province'],
			'COUNTRY' => $values['country'],
			'COUNTRY_CODE' => $values['countryCode'],
		];
	}

	private function createAddress(array $address): Address
	{
		return Address::fromArray([
			'address1' => $address['ADDRESS_1'] ?? '',
			'address2' => $address['ADDRESS_2'] ?? '',
			'city' => $address['CITY'] ?? '',
			'postalCode' => $address['POSTAL_CODE'] ?? '',
			'region' => $address['REGION'] ?? '',
			'province' => $address['PROVINCE'] ?? '',
			'country' => $address['COUNTRY'] ?? '',
			'countryCode' => $address['COUNTRY_CODE'] ?? '',
		]);
	}

	/**
	 * @param array<int, Address> $addresses
	 * @param array<string, mixed> $filter
	 * @return array<int, Address>
	 */
	private function filterAddresses(array $addresses, array $filter): array
	{
		return array_filter($addresses, function (Address $address, int $typeId) use ($filter): bool {
			$values = ['typeId' => $typeId] + $address->toArray();
			foreach ($filter as $field => $value)
			{
				if ($field === 'requisiteId')
				{
					continue;
				}
				if (!array_key_exists($field, $values) || $values[$field] !== $value)
				{
					return false;
				}
			}

			return true;
		}, ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * @param array<int, Address> $addresses
	 * @param array<string, string> $order
	 */
	private function sortAddresses(array &$addresses, array $order): void
	{
		$items = [];
		foreach ($addresses as $typeId => $address)
		{
			$items[] = ['typeId' => $typeId, 'address' => $address];
		}
		usort($items, function (array $left, array $right) use ($order): int {
			$leftValues = ['typeId' => $left['typeId']] + $left['address']->toArray();
			$rightValues = ['typeId' => $right['typeId']] + $right['address']->toArray();
			foreach ($order as $field => $direction)
			{
				$result = $leftValues[$field] <=> $rightValues[$field];
				if ($result !== 0)
				{
					return $direction === 'DESC' ? -$result : $result;
				}
			}

			return 0;
		});
		$addresses = [];
		foreach ($items as $item)
		{
			$addresses[$item['typeId']] = $item['address'];
		}
	}
}
