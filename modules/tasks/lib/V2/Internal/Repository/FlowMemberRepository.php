<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Flow\Internal\FlowMemberTable;
use Bitrix\Tasks\V2\Internal\Entity\FlowModel\EntityType;

class FlowMemberRepository implements FlowMemberRepositoryInterface
{
	/**
	 * @param int $flowId
	 * @return array
	 */
	public function getDepartmentsOldIdsByType(int $flowId): array
	{
		$result = [];

		$query = FlowMemberTable::query()
			->setSelect(['ENTITY_ID', 'ENTITY_TYPE'])
			->where('FLOW_ID', $flowId)
			->whereIn(
				'ENTITY_TYPE',
				[
					EntityType::Department->value,
					EntityType::DepartmentRecursive->value,
				],
			)
			->exec()
		;

		while ($row = $query->fetch())
		{
			$result[$row['ENTITY_TYPE']][] = (int)$row['ENTITY_ID'];
		}

		return $result;
	}
}
