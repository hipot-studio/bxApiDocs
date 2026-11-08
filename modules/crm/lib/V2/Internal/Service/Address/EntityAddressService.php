<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;
use Bitrix\Main\ObjectNotFoundException;

class EntityAddressService
{
	public function __construct(
		private readonly AddressStorage $storage = new AddressStorage(),
	)
	{
	}

	public function assertIdentifierBelongsToOwner(AddressIdentifier $identifier, AddressOwner $owner): void
	{
		if (
			$identifier->getRecordTypeId() !== $owner->getEntityTypeId()
			|| $identifier->getRecordId() !== $owner->getEntityId()
		)
		{
			throw new ObjectNotFoundException($this->getAddressNotFoundMessage($identifier));
		}
	}

	public function assertCanRead(AddressOwner $owner, int $userId): void
	{
		if (
			!$this->ownerExists($owner)
			|| !Container::getInstance()->getUserPermissions($userId)->item()->canRead(
				$owner->getEntityTypeId(),
				$owner->getEntityId(),
			)
		)
		{
			throw new ObjectNotFoundException($this->getOwnerNotFoundMessage($owner));
		}
	}

	public function assertCanUpdate(AddressOwner $owner, int $userId): void
	{
		if (
			!$this->ownerExists($owner)
			|| !Container::getInstance()->getUserPermissions($userId)->item()->canUpdate(
				$owner->getEntityTypeId(),
				$owner->getEntityId(),
			)
		)
		{
			throw new ObjectNotFoundException($this->getOwnerNotFoundMessage($owner));
		}
	}

	public function assertCanDelete(AddressOwner $owner, int $userId): void
	{
		if (
			!$this->ownerExists($owner)
			|| !Container::getInstance()->getUserPermissions($userId)->item()->canDelete(
				$owner->getEntityTypeId(),
				$owner->getEntityId(),
			)
		)
		{
			throw new ObjectNotFoundException($this->getOwnerNotFoundMessage($owner));
		}
	}

	public function read(AddressIdentifier $identifier): Address
	{
		$address = $this->storage->find($identifier);

		return Address::fromArray($address?->toArray() ?? []);
	}

	public function update(AddressIdentifier $identifier, AddressOwner $owner, Address $changes): void
	{
		$current = $this->read($identifier);
		$merged = $current->applyChanges($changes);
		if ($current->toArray() !== $merged->toArray())
		{
			$this->save($identifier, $owner, $merged);
		}
	}

	public function save(AddressIdentifier $identifier, AddressOwner $owner, Address $address): void
	{
		$values = $address->toArray();
		EntityAddress::register(
			$identifier->getRecordTypeId(),
			$identifier->getRecordId(),
			$identifier->getTypeId(),
			[
				'ANCHOR_TYPE_ID' => $owner->getEntityTypeId(),
				'ANCHOR_ID' => $owner->getEntityId(),
				'ADDRESS_1' => $values['address1'],
				'ADDRESS_2' => $values['address2'],
				'CITY' => $values['city'],
				'POSTAL_CODE' => $values['postalCode'],
				'REGION' => $values['region'],
				'PROVINCE' => $values['province'],
				'COUNTRY' => $values['country'],
				'COUNTRY_CODE' => $values['countryCode'],
			],
		);
	}

	public function has(AddressIdentifier $identifier): bool
	{
		return $this->storage->exists($identifier);
	}

	public function delete(AddressIdentifier $identifier): void
	{
		if (!$this->has($identifier))
		{
			throw new ObjectNotFoundException($this->getAddressNotFoundMessage($identifier));
		}

		EntityAddress::unregister(
			$identifier->getRecordTypeId(),
			$identifier->getRecordId(),
			$identifier->getTypeId(),
		);
	}

	private function ownerExists(AddressOwner $owner): bool
	{
		$factory = Container::getInstance()->getFactory($owner->getEntityTypeId());

		return $factory?->getItem($owner->getEntityId(), ['ID']) !== null;
	}

	private function getOwnerNotFoundMessage(AddressOwner $owner): string
	{
		return sprintf(
			'Address owner not found: %d:%d',
			$owner->getEntityTypeId(),
			$owner->getEntityId(),
		);
	}

	private function getAddressNotFoundMessage(AddressIdentifier $identifier): string
	{
		return sprintf(
			'Address not found: %d:%d:%d',
			$identifier->getRecordTypeId(),
			$identifier->getRecordId(),
			$identifier->getTypeId(),
		);
	}
}
