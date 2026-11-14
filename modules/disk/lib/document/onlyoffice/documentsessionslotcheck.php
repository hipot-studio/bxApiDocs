<?php

namespace Bitrix\Disk\Document\OnlyOffice;

/**
 * What one check of the restriction journal answered: whether a slot is free, and how many slots were
 * free at the moment of the check.
 *
 * The number is taken under the lock of the check, so the caller that goes on to reserve the slot
 * under the same lock publishes it without counting the journal anew.
 */
final class DocumentSessionSlotCheck
{
	private function __construct(
		private readonly bool $slotFree,
		private readonly int $availableCount,
	)
	{
	}

	public static function unlimited(): self
	{
		return new self(true, RestrictionManager::UNLIMITED_VALUE);
	}

	public static function counted(int $limit, int $countSessions): self
	{
		return new self($limit > $countSessions, self::availableCount($limit, $countSessions));
	}

	public static function availableCount(int $limit, int $countSessions): int
	{
		return
			$limit >= RestrictionManager::UNLIMITED_VALUE
				? RestrictionManager::UNLIMITED_VALUE
				: $limit - $countSessions
		;
	}

	public function isSlotFree(): bool
	{
		return $this->slotFree;
	}

	public function getAvailableCount(): int
	{
		return $this->availableCount;
	}

	public function getAvailableCountAfterReservation(): int
	{
		if ($this->availableCount === RestrictionManager::UNLIMITED_VALUE)
		{
			return RestrictionManager::UNLIMITED_VALUE;
		}

		return $this->availableCount - 1;
	}
}
