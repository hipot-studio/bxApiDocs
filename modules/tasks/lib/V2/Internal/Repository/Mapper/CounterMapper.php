<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;
use Bitrix\Tasks\V2\Internal\Entity\Counter;

class CounterMapper
{
	/** @return array{ID?: int, USER_ID?: int, TASK_ID?: int, GROUP_ID?: int, TYPE?: string, VALUE?: int} */
	public function mapFromEntity(Counter $entity): array
	{
		$data = [];

		if ($entity->getId() !== null)
		{
			$data['ID'] = $entity->getId();
		}

		$data['USER_ID'] = $entity->userId;
		$data['TASK_ID'] = $entity->taskId;
		$data['GROUP_ID'] = $entity->groupId;
		$data['TYPE'] = $entity->type;
		$data['VALUE'] = $entity->value ?? 0;

		return $data;
	}

	/** @return array{ID?: int, USER_ID?: int, TASK_ID?: int, GROUP_ID?: int, TYPE?: string, VALUE?: int}[] */
	public function mapFromCollection(CounterCollection $collection): array
	{
		return array_map([$this, 'mapFromEntity'], $collection->getEntities());
	}
}
