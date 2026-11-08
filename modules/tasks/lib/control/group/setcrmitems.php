<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Control\Group;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Command\AddItemsCommand;
use Bitrix\Tasks\V2\Internal\Result\Result;
use CUserTypeManager;

class SetCrmItems
{
	/**
	 * Adds a single CRM element to every task in the chunk (ADD, not overwrite), reusing the validated
	 * AddItemsCommand path (CrmItemService::add -> UpdateTaskService::update). Access is checked by the
	 * caller; only concrete elements ({ABBR}_{id}) reach here. Tasks that already have every requested
	 * element bound are skipped, so the mass action does not fire redundant no-op updates (events,
	 * counters, reindex) via CrmItemService::add.
	 *
	 * @param string[] $crmItemIds
	 */
	public function runBatch(int $userId, array $taskIds, array $crmItemIds): array
	{
		$result = [];

		foreach ($taskIds as $id)
		{
			$id = (int)$id;

			if ($this->isAlreadyBound($id, $crmItemIds))
			{
				$result[] = [new Result(), 'taskId' => $id];
				continue;
			}

			$result[] = [
				(new AddItemsCommand(
					taskId: $id,
					userId: $userId,
					crmItemIds: $crmItemIds,
					useConsistency: true,
				))->run(),
				'taskId' => $id,
			];
		}

		return $result;
	}

	private function isAlreadyBound(int $taskId, array $crmItemIds): bool
	{
		$current = $this->getBoundCrmItems($taskId);

		foreach ($crmItemIds as $crmItemId)
		{
			if (!in_array($crmItemId, $current, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string[]
	 */
	private function getBoundCrmItems(int $taskId): array
	{
		$current = $this->getUfManager()->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_CRM]['VALUE'] ?? [];

		return is_array($current) ? array_map('strval', $current) : [];
	}

	private function getUfManager(): CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}
}
