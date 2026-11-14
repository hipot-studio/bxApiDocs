<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Intranet\UserTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserUtils;

class EmployeeSearchRepository
{
	/**
	 * @return array<int, bool>
	 */
	public function findCandidates(string $query, int $limit): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$query = self::normalizeQuery($query);
		if (!preg_match('/[\p{L}\p{N}]/u', $query))
		{
			return [];
		}

		$searchFilter = UserUtils::getUserSearchFilter(['FIND' => $query]);
		if ($searchFilter === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID', 'ACTIVE'])
			->setFilter($searchFilter)
			->where('USER_TYPE_IS_EMPLOYEE', 1)
			->where('REAL_USER', 'expr', true)
			->setOrder(['ACTIVE' => 'DESC', 'LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		$candidates = [];
		foreach ($rows as $row)
		{
			$candidates[(int)$row['ID']] = $row['ACTIVE'] === 'Y';
		}

		return $candidates;
	}

	public static function normalizeQuery(string $query): string
	{
		return trim(preg_replace('/[\s%_]+/u', ' ', $query) ?? '');
	}
}
