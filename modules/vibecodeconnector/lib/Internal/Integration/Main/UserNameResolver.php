<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\UserTable;
use CUser;
use CSite;

final class UserNameResolver
{
	public function resolveMany(array $userIds): array
	{
		$uniqueIds = array_values(array_unique(array_filter(
			$userIds,
			static fn(int $id): bool => $id > 0,
		)));

		if ($uniqueIds === [])
		{
			return [];
		}

		$rows = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL'],
			'filter' => [
				'=ID' => $uniqueIds,
				'=ACTIVE' => 'Y',
			],
		])->fetchAll();

		$nameFormat = CSite::GetNameFormat();
		$result = [];

		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$formatted = CUser::FormatName($nameFormat, $row, true, false);
			$result[$id] = trim($formatted) !== '' ? $formatted : null;
		}

		return $result;
	}
}
