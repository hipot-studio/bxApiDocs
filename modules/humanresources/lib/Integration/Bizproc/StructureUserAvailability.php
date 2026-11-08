<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\Main\UserTable;

/**
 * Which of the people a structure read gave back are still available: a fired or switched off account and
 * a technical one of an application are members of the structure all the same.
 */
class StructureUserAvailability
{
	/**
	 * @param list<int> $userIds
	 * @return list<int> the active real users among them, in the order they came in
	 */
	public function filterActive(array $userIds): array
	{
		if (!$userIds)
		{
			return [];
		}

		$rows = UserTable::getList([
			'select' => ['ID'],
			'filter' => ['=ACTIVE' => 'Y', '=IS_REAL_USER' => 'Y', '=ID' => $userIds],
		]);

		$active = [];
		while ($row = $rows->fetch())
		{
			$active[(int)$row['ID']] = true;
		}

		return array_values(array_filter($userIds, static fn(int $userId): bool => isset($active[$userId])));
	}
}
