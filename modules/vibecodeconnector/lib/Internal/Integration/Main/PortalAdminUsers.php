<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\UserTable;

final class PortalAdminUsers
{
	private const BATCH_SIZE = 500;

	public function getProfiles(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		if ($userIds === [])
		{
			return [];
		}

		$profiles = [];
		foreach (array_chunk($userIds, self::BATCH_SIZE) as $batch)
		{
			$rows = UserTable::getList([
				'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'ACTIVE'],
				'filter' => ['@ID' => $batch],
			]);
			while ($row = $rows->fetch())
			{
				$profiles[(int)$row['ID']] = $row;
			}
		}

		return $profiles;
	}
}
