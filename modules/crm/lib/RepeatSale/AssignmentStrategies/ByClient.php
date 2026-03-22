<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class ByClient extends Base
{
	private const AVAILABLE_ENTITY_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
	];

	private ?array $clientAssignmentIds = [];

	public function getAssignmentUserId(Item $item, ?int $lastAssignmentUserId): ?int
	{
		$ids = $this->getAssignmentIds();

		return $ids[$item->getId()] ?? $lastAssignmentUserId;
	}

	private function getAssignmentIds(): array
	{
		if (!isset($this->clientAssignmentIds[$this->entityTypeId]))
		{
			$clientEntityIds = array_map(static fn($item) => $item->getId(), $this->items);

			if (!$this->check($clientEntityIds))
			{
				$this->clientAssignmentIds[$this->entityTypeId] = [];

				return [];
			}

			$queryResult = Container::getInstance()
				->getFactory($this->entityTypeId)
				?->getItems([
					'select' => [
						Item::FIELD_NAME_ID,
						Item::FIELD_NAME_ASSIGNED,
					],
					'filter' => [
						'@' . Item::FIELD_NAME_ID => $clientEntityIds,
					],
				])
			;

			$clients = [];
			foreach ($queryResult as $item)
			{
				$assignmentUserId = isset($item[Item::FIELD_NAME_ASSIGNED]) ? (int)$item[Item::FIELD_NAME_ASSIGNED] : null;
				$clients[$item[Item::FIELD_NAME_ID]] = $assignmentUserId;
			}

			$this->clientAssignmentIds[$this->entityTypeId] = $clients;
		}

		return $this->clientAssignmentIds[$this->entityTypeId];
	}

	private function check(array $clientEntityIds): bool
	{
		if (empty($clientEntityIds))
		{
			return false;
		}

		return in_array($this->entityTypeId, self::AVAILABLE_ENTITY_TYPES, true);
	}
}
