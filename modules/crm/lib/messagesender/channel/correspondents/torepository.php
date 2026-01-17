<?php

namespace Bitrix\Crm\MessageSender\Channel\Correspondents;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;

final class ToRepository
{
	private int $userId;
	private bool $checkPermissions = true;

	private ?array $toListByType = null;

	public static function create(ItemIdentifier $item): self
	{
		return new self($item);
	}

	private function __construct(
		private readonly ItemIdentifier $item,
	)
	{
		$this->userId = Container::getInstance()->getContext()->getUserId();
	}

	private function __clone(): void
	{
	}

	public function setCheckPermissions(bool $checkPermissions): self
	{
		$this->checkPermissions = $checkPermissions;
		$this->resetCache();

		return $this;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;
		$this->resetCache();

		return $this;
	}

	public function getItemIdentifier(): ItemIdentifier
	{
		return $this->item;
	}

	/**
	 * @return To[]
	 */
	public function getAll(): array
	{
		$this->loadIfNeeded();

		return self::prioritizeToList(array_merge(...array_values($this->toListByType)));
	}

	/**
	 * @return array<string, To[]>
	 */
	public function getAllSeparatedByType(): array
	{
		$this->loadIfNeeded();

		return $this->toListByType;
	}

	/**
	 * @return To[]
	 */
	public function getListByType(string $typeId, ?string $valueTypeId = null): array
	{
		$this->loadIfNeeded();

		$toList = $this->toListByType[$typeId] ?? [];
		if (empty($toList))
		{
			return [];
		}

		if ($valueTypeId !== null)
		{
			$toList = array_filter($toList, fn(To $to) => $to->getAddress()->getValueType() === $valueTypeId);
		}

		return $toList;
	}

	public function getFirstByType(string $typeId, ?string $valueTypeId = null): ?To
	{
		$list = $this->getListByType($typeId, $valueTypeId);

		return array_shift($list);
	}

	private function resetCache(): void
	{
		$this->toListByType = null;
	}

	private function loadIfNeeded(): void
	{
		$this->toListByType ??= $this->load();
	}

	private function load(): array
	{
		$permissions = Container::getInstance()->getUserPermissions($this->userId);

		if ($this->checkPermissions && !$permissions->item()->canReadItemIdentifier($this->item))
		{
			return [];
		}

		$holders = self::getCommunicationsHolders($this->item);
		if ($this->checkPermissions)
		{
			foreach ($holders as $entityTypeId => &$itemIds)
			{
				$itemIds = array_filter($itemIds, fn(int $id) => $permissions->item()->canRead($entityTypeId, $id));
			}
		}

		if (empty($holders))
		{
			return [];
		}

		$storage = Container::getInstance()->getMultifieldStorage();

		$toListByType = [];
		foreach ($holders as $entityTypeId => $multipleEntityIds)
		{
			if (empty($multipleEntityIds))
			{
				continue;
			}

			$multifieldsForMultipleOwners = $storage->getForMultipleOwners($entityTypeId, $multipleEntityIds);

			foreach ($multifieldsForMultipleOwners as $entityId => $multifields)
			{
				foreach ($multifields as $value)
				{
					$toListByType[$value->getTypeId()][] = new To(
						$this->item,
						new ItemIdentifier($entityTypeId, $entityId),
						$value,
					);
				}
			}
		}

		return array_map(self::prioritizeToList(...), $toListByType);
	}

	/**
	 * @param ItemIdentifier $source
	 * @return Array<int, int[]> entityTypeId => itemIds
	 */
	private static function getCommunicationsHolders(ItemIdentifier $source): array
	{
		if ($source->getEntityTypeId() === \CCrmOwnerType::Order)
		{
			return self::getCommunicationsHoldersForOrder($source);
		}

		if (!\CCrmOwnerType::isUseFactoryBasedApproach($source->getEntityTypeId()))
		{
			return [];
		}

		$sourceItem = self::fetchItem($source->getEntityTypeId(), $source->getEntityId());
		if (!$sourceItem)
		{
			return [];
		}

		$result = [
			\CCrmOwnerType::Contact => [],
			\CCrmOwnerType::Company => [],
			\CCrmOwnerType::Lead => [],
		];

		if (isset($result[$source->getEntityTypeId()]))
		{
			$result[$source->getEntityTypeId()][$source->getEntityId()] = $source->getEntityId();
		}

		if ($sourceItem->hasField(Item::FIELD_NAME_COMPANY_ID) && $sourceItem->getCompanyId() > 0)
		{
			$result[\CCrmOwnerType::Company][$sourceItem->getCompanyId()] = $sourceItem->getCompanyId();
		}

		if (
			$sourceItem->hasField(Item\Contact::FIELD_NAME_COMPANY_IDS)
			&& !empty($sourceItem->get(Item\Contact::FIELD_NAME_COMPANY_IDS))
		)
		{
			foreach ($sourceItem->get(Item\Contact::FIELD_NAME_COMPANY_IDS) as $companyId)
			{
				if ((int)$companyId > 0)
				{
					$result[\CCrmOwnerType::Company][$companyId] = (int)$companyId;
				}
			}
		}

		if ($sourceItem->hasField(Item::FIELD_NAME_CONTACT_ID) && $sourceItem->getContactId() > 0)
		{
			$result[\CCrmOwnerType::Contact][$sourceItem->getContactId()] = $sourceItem->getContactId();
		}

		if ($sourceItem->hasField(Item::FIELD_NAME_CONTACT_IDS) && !empty($sourceItem->getContactIds()))
		{
			foreach ($sourceItem->getContactIds() as $contactId)
			{
				if ((int)$contactId > 0)
				{
					$result[\CCrmOwnerType::Contact][$contactId] = (int)$contactId;
				}
			}
		}

		return $result;
	}

	/**
	 * @param ItemIdentifier $source
	 * @return Array<int, int[]> entityTypeId => itemIds
	 * @throws ArgumentException
	 */
	private static function getCommunicationsHoldersForOrder(ItemIdentifier $source): array
	{
		if ($source->getEntityTypeId() !== \CCrmOwnerType::Order)
		{
			throw new ArgumentException('Cant process anything but order in this method');
		}

		$dbRes = \Bitrix\Crm\Order\ContactCompanyCollection::getList([
			'select' => ['ENTITY_ID', 'ENTITY_TYPE_ID'],
			'filter' => [
				'=ORDER_ID' => $source->getEntityId(),
				'=IS_PRIMARY' => 'Y'
			]
		]);

		$result = [
			\CCrmOwnerType::Contact => [],
			\CCrmOwnerType::Company => [],
		];

		while ($entity = $dbRes->fetch())
		{
			$entityTypeId = (int)($entity['ENTITY_TYPE_ID'] ?? 0);
			if (!isset($result[$entityTypeId]))
			{
				continue;
			}

			$entityId = (int)($entity['ENTITY_ID'] ?? 0);
			if ($entityId <= 0)
			{
				continue;
			}

			$result[$entityTypeId][$entityId] = $entityId;
		}

		return $result;
	}

	private static function fetchItem(int $entityTypeId, int $id): ?Item
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
		{
			return null;
		}

		$possibleSelect = [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_CONTACT_ID,
			Item::FIELD_NAME_CONTACT_IDS,
			Item::FIELD_NAME_COMPANY_ID,
			Item\Contact::FIELD_NAME_COMPANY_IDS,
		];

		$filteredSelect = array_filter($possibleSelect, $factory->isFieldExists(...));
		if (empty($filteredSelect))
		{
			throw new InvalidOperationException('We have no fields to select, it should be impossible');
		}

		$items = $factory->getItems([
			'select' => $filteredSelect,
			'filter' => [
				'=ID' => $id,
			],
		]);

		return array_shift($items);
	}

	private static function prioritizeToList(array $toList): array
	{
		static $entityPriority = [
			\CCrmOwnerType::Company => 100,
			\CCrmOwnerType::Contact => 50,
			\CCrmOwnerType::Lead => 10,
		];

		usort($toList, function(To $a, To $b) use ($entityPriority) {
			$priorityA = $entityPriority[$a->getAddressSource()->getEntityTypeId()] ?? 0;
			$priorityB = $entityPriority[$b->getAddressSource()->getEntityTypeId()] ?? 0;

			if ($priorityA === $priorityB)
			{
				return $a->getAddressSource()->getEntityId() <=> $b->getAddressSource()->getEntityId();
			}

			return $priorityA <=> $priorityB;
		});

		return $toList;
	}
}
