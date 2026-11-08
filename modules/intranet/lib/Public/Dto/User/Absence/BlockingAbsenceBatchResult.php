<?php

declare(strict_types= 1);

namespace Bitrix\Intranet\Public\Dto\User\Absence;

final readonly class BlockingAbsenceBatchResult
{
	/**
	 * @param array<int, bool> $hasBlockingAbsenceByUserId
	 * @param int[] $untrustedUserIds
	 */
	public function __construct(
		public array $hasBlockingAbsenceByUserId,
		public array $untrustedUserIds,
	)
	{
	}

	public function getForUser(int $userId): ?bool
	{
		return $this->hasBlockingAbsenceByUserId[$userId] ?? null;
	}

	public function isTrusted(int $userId): bool
	{
		return array_key_exists($userId, $this->hasBlockingAbsenceByUserId)
			&& !in_array($userId, $this->untrustedUserIds, true);
	}
}
