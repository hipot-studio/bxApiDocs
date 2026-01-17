<?php

namespace Bitrix\Tasks\Control\Group;

use Bitrix\Tasks\Control\Task;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Event\Task\OnCreatorUpdatedEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class SetOriginator
{
	public function runBatch(int $userId, array $taskIds, int $originatorId, ?int $responsibleId = null): array
	{
		$result = [];
		$control = (new Task($userId))->useConsistency();

		$fields = [
			'CREATED_BY' => $originatorId,
		];

		if ($responsibleId !== null)
		{
			$fields['RESPONSIBLE_ID'] = $responsibleId;
		}

		foreach ($taskIds as $id)
		{
			$result[] = [
				$control->update($id, $fields),
				'taskId' => $id,
			];
		}

		return $result;
	}
}
