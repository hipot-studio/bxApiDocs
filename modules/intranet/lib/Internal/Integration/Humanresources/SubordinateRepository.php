<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Public\Service;
use Bitrix\Main\UserTable;

class SubordinateRepository
{
	public function getSubordinateIds(int $userId, int $departmentId, int $lastUserId, int $limit): array
	{
		$query = Service\Container::getNodeMemberService()->injectUserNodeSubquery(
			UserTable::query(),
			nodeTypes: null,
			active: true,
			nodeIds: [$departmentId],
		);
		$result = $query
			->setSelect(['ID'])
			->setDistinct()
			->where('IS_REAL_USER', 'Y')
			->where('ID', '!=', $userId)
			->where('ID', '>', $lastUserId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit + 1)
			->exec()
		;
		$ids = [];
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

}
