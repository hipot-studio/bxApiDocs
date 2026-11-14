<?php

namespace Bitrix\StaffTrack\Internal\Integration\Intranet;

use Bitrix\Intranet\UserTable as IntranetUserTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;

/**
 * Who of a set of people may be on the staff of an office: an active employee of the intranet and nobody
 * else - a dismissed account, a guest of the extranet and a mail or service record all stay out,
 * whichever way they got into the set.
 *
 * The membership of the structure is not trusted with this: its activity follows the account by an event
 * whose failures are swallowed, and the flag of a real user lets the extranet through.
 */
class EmployeeEligibility
{
	private const EMPLOYEE_USER_TYPE = 'employee';
	private const QUERY_CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return int[] the eligible ones, in the order they were given
	 */
	public function filter(array $userIds): array
	{
		$userIds = IdNormalizer::normalize($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$eligible = [];

		foreach (array_chunk($userIds, self::QUERY_CHUNK_SIZE) as $chunk)
		{
			$rows = $this->prepareQuery()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->where('ACTIVE', 'Y')
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$eligible[(int)$row['ID']] = true;
			}
		}

		return array_values(array_filter($userIds, static fn (int $userId): bool => isset($eligible[$userId])));
	}

	protected function hasIntranet(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * Without the intranet there is no extranet to tell apart either, and the flag of a real user is the
	 * best the portal knows.
	 */
	private function prepareQuery(): Query
	{
		if ($this->hasIntranet())
		{
			return IntranetUserTable::query()->where('USER_TYPE', self::EMPLOYEE_USER_TYPE);
		}

		return UserTable::query()->where('IS_REAL_USER', 'Y');
	}
}
