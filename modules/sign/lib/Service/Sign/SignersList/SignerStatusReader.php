<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

use Bitrix\Main\UserTable;

/**
 * Reads employment-status fields (activity and department membership) for a set of
 * users in a single batch query, so signer eligibility can be resolved without an N+1.
 */
class SignerStatusReader
{
	private const READ_CHUNK_SIZE = 300;

	/**
	 * Expects a list of unique user ids. The set is read in bounded chunks and streamed,
	 * so a large department expansion cannot blow up into a single oversized IN (...) or
	 * hit the DB parameter limit / memory ceiling.
	 *
	 * @param int[] $userIds
	 *
	 * @return array<int, array{active: bool, departmentIds: int[]}> indexed by user id;
	 *         users that do not exist are absent from the result
	 */
	public function readByUserIds(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$statuses = [];
		foreach (array_chunk($userIds, self::READ_CHUNK_SIZE) as $userIdChunk)
		{
			$rows = UserTable::query()
				->setSelect(['ID', 'ACTIVE', 'UF_DEPARTMENT'])
				->whereIn('ID', $userIdChunk)
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$departments = is_array($row['UF_DEPARTMENT'] ?? null) ? $row['UF_DEPARTMENT'] : [];
				$departmentIds = array_values(array_filter(
					array_map('intval', $departments),
					static fn(int $id): bool => $id > 0,
				));

				$statuses[(int)$row['ID']] = [
					'active' => ($row['ACTIVE'] ?? 'N') === 'Y',
					'departmentIds' => $departmentIds,
				];
			}
		}

		return $statuses;
	}
}
