<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Address\AddressPlacement;
use Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository;
use Bitrix\Main\Result;

final class AddressStorage
{
	public function __construct(
		private readonly AddressRepository $repository = new AddressRepository(),
	)
	{
	}

	public function find(AddressIdentifier $identifier): ?Address
	{
		$row = $this->repository->findByIdentifier($identifier);

		return $row === null ? null : Address::fromArray(OwnerAddressFieldMap::toAddressValues($row));
	}

	public function findByPlacement(AddressPlacement $placement): ?Address
	{
		return $this->find($placement->getIdentifier());
	}

	public function exists(AddressIdentifier $identifier): bool
	{
		return $this->repository->findByIdentifier($identifier) !== null;
	}

	public function merge(AddressPlacement $placement, Address $address, array $fields = []): void
	{
		$this->repository->merge($this->buildStoredFields($placement, $address, $fields));
	}

	public function add(AddressPlacement $placement, Address $address, array $fields = []): Result
	{
		return $this->repository->add($this->buildStoredFields($placement, $address, $fields));
	}

	public function update(AddressPlacement $placement, Address $changes): Result
	{
		$current = $this->findByPlacement($placement);
		if ($current === null)
		{
			return new Result();
		}

		$merged = $current->applyChanges($changes);
		if ($merged->toArray() === $current->toArray())
		{
			return new Result();
		}

		return $this->repository->update(
			$placement->typeId,
			$placement->recordTypeId,
			$placement->recordId,
			OwnerAddressFieldMap::toStoredFields($merged->toArray()),
		);
	}

	public function delete(AddressIdentifier $identifier): Result
	{
		return $this->repository->delete($identifier->typeId, $identifier->recordTypeId, $identifier->recordId);
	}

	public function findRowById(int $id): ?array
	{
		return $this->repository->findById($id);
	}

	public function findRow(AddressIdentifier $identifier): ?array
	{
		return $this->repository->findByIdentifier($identifier);
	}

	/** @return array<int, array<string, mixed>> */
	public function findRowsByRecord(int $recordTypeId, int $recordId): array
	{
		return $this->repository->findAllByRecord($recordTypeId, $recordId);
	}

	/** @return array<int, array<string, mixed>> */
	public function findRowsByOwner(int $ownerTypeId, int $ownerId): array
	{
		return $this->repository->findAllByAnchorOwner($ownerTypeId, $ownerId);
	}

	public function findRequisiteOfDefaultAddress(int $ownerTypeId, int $ownerId, int $typeId): int
	{
		return $this->repository->findRequisiteOfDefaultAddressWithoutPermissionCheck(
			$ownerTypeId,
			$ownerId,
			$typeId,
		);
	}

	public function addRow(array $fields): Result
	{
		return $this->repository->add($fields);
	}

	public function mergeRow(array $fields): void
	{
		$this->repository->merge($fields);
	}

	public function updateRow(int $typeId, int $recordTypeId, int $recordId, array $fields): Result
	{
		return $this->repository->update($typeId, $recordTypeId, $recordId, $fields);
	}

	public function deleteRow(int $typeId, int $recordTypeId, int $recordId): Result
	{
		return $this->repository->delete($typeId, $recordTypeId, $recordId);
	}

	public function setDefaultAmongOwnerRecords(int $typeId, int $recordTypeId, int $recordId): void
	{
		$this->repository->setDefaultAmongOwnerRecords($typeId, $recordTypeId, $recordId);
	}

	private function buildStoredFields(AddressPlacement $placement, Address $address, array $fields): array
	{
		return OwnerAddressFieldMap::toStoredFields($address->toArray()) + [
			'TYPE_ID' => $placement->typeId,
			'ENTITY_TYPE_ID' => $placement->recordTypeId,
			'ENTITY_ID' => $placement->recordId,
			'ANCHOR_TYPE_ID' => $placement->ownerTypeId,
			'ANCHOR_ID' => $placement->ownerId,
		] + $fields;
	}
}
