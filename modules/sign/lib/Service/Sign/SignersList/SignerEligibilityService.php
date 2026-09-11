<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

/**
 * Server-side guard that decides whether users may be added to a B2E signers list.
 *
 * A user must not be added when they are fired (ACTIVE = 'N') or an extranet user
 * (no department). The UI selector already hides such users; this guard closes the
 * same hole for direct controller requests. Users are resolved in a single batch
 * query to avoid an N+1.
 */
class SignerEligibilityService
{
	private readonly SignerStatusReader $statusReader;

	public function __construct(?SignerStatusReader $statusReader = null)
	{
		$this->statusReader = $statusReader ?? new SignerStatusReader();
	}

	/**
	 * @param int[] $userIds
	 */
	public function classify(array $userIds): SignerEligibilityResult
	{
		$userIds = $this->normalizeIds($userIds);
		if ($userIds === [])
		{
			return new SignerEligibilityResult();
		}

		$statuses = $this->statusReader->readByUserIds($userIds);

		$firedUserIds = [];
		$extranetUserIds = [];
		$notFoundUserIds = [];
		foreach ($userIds as $userId)
		{
			$status = $statuses[$userId] ?? null;
			if ($status === null)
			{
				$notFoundUserIds[] = $userId;

				continue;
			}

			if (!$status['active'])
			{
				$firedUserIds[] = $userId;

				continue;
			}

			if ($status['departmentIds'] === [])
			{
				$extranetUserIds[] = $userId;
			}
		}

		return new SignerEligibilityResult($firedUserIds, $extranetUserIds, $notFoundUserIds);
	}

	/**
	 * Casts ids to int and drops duplicates. Non-positive ids are intentionally kept:
	 * they match no existing user and must surface as "not found" so the request is
	 * rejected instead of an id like 0 slipping into the list unchecked.
	 *
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	private function normalizeIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$normalized[(int)$userId] = true;
		}

		return array_keys($normalized);
	}
}
