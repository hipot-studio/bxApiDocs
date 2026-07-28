<?php

namespace Bitrix\BIConnector\Internal\Services\Scope;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Flow\Provider\FlowProvider;
use Bitrix\Tasks\Flow\Provider\Query\ExpandedFlowQuery;

class TasksFlowAccessibilityService
{
	/**
	 * @param array<string, string> $orderBy ORM order applied in SQL.
	 *
	 * @return array<array{id:int, name:string}>
	 */
	public function findAccessibleForUser(
		int $userId,
		?string $search,
		int $limit,
		int $offset,
		array $orderBy = ['NAME' => 'ASC'],
	): array
	{
		if ($userId <= 0 || !Loader::includeModule('tasks'))
		{
			return [];
		}

		$query = (new ExpandedFlowQuery($userId))
			->setSelect(['ID', 'NAME'])
			->setOrderBy($orderBy)
		;

		if ($limit > 0)
		{
			$query->setLimit($limit)->setOffset($offset);
		}

		if ($search !== null && $search !== '')
		{
			$query->whereName('%' . $search . '%', 'like');
		}

		$flows = (new FlowProvider())->getList($query);
		$result = [];
		foreach ($flows as $flow)
		{
			$result[] = [
				'id' => (int)$flow->getId(),
				'name' => (string)$flow->getName(),
			];
		}

		return $result;
	}

	public function isAccessibleForUser(int $userId, int $flowId): bool
	{
		if ($userId <= 0 || $flowId <= 0 || !Loader::includeModule('tasks'))
		{
			return false;
		}

		$query = (new ExpandedFlowQuery($userId))
			->setSelect(['ID'])
			->setLimit(1)
			->whereId($flowId)
		;

		return !(new FlowProvider())->getList($query)->isEmpty();
	}
}
