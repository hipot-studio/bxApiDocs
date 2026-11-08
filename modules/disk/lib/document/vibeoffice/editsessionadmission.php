<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;

class EditSessionAdmission
{
	public const OUTCOME_ALLOWED = 'allowed';
	public const OUTCOME_LIMIT_EXCEEDED = 'limitExceeded';
	public const OUTCOME_LOCK_UNAVAILABLE = 'lockUnavailable';

	private readonly RestrictionManager $restrictionManager;

	public function __construct(?RestrictionManager $restrictionManager = null)
	{
		$this->restrictionManager = $restrictionManager ?? new RestrictionManager();
	}

	public function admit(int $objectId, int $userId, string $reservationKey): EditSessionAdmissionTicket
	{
		if (!$this->shouldUseRestriction())
		{
			return new EditSessionAdmissionTicket(self::OUTCOME_ALLOWED);
		}

		if (!$this->lock())
		{
			return new EditSessionAdmissionTicket(self::OUTCOME_LOCK_UNAVAILABLE);
		}

		try
		{
			if ($this->existsUsage($objectId, $userId))
			{
				return new EditSessionAdmissionTicket(self::OUTCOME_ALLOWED);
			}

			if (!$this->hasAvailableSlot())
			{
				return new EditSessionAdmissionTicket(self::OUTCOME_LIMIT_EXCEEDED);
			}

			$reservationCreated = $this->registerPendingUsage($reservationKey, $userId);
			if ($reservationCreated === null)
			{
				return new EditSessionAdmissionTicket(self::OUTCOME_LOCK_UNAVAILABLE);
			}

			return new EditSessionAdmissionTicket(
				self::OUTCOME_ALLOWED,
				$reservationCreated ? $this->getRestrictionManager() : null,
				$reservationKey,
				$userId,
			);
		}
		finally
		{
			$this->unlock();
		}
	}

	protected function shouldUseRestriction(): bool
	{
		return $this->getRestrictionManager()->shouldUseRestriction();
	}

	protected function lock(): bool
	{
		return $this->getRestrictionManager()->lock();
	}

	protected function unlock(): void
	{
		$this->getRestrictionManager()->unlock();
	}

	protected function hasAvailableSlot(): bool
	{
		return $this->getRestrictionManager()->hasAvailableDocumentSessionSlot();
	}

	protected function existsUsage(int $objectId, int $userId): bool
	{
		return $this->getRestrictionManager()->existsUsageForObject($objectId, $userId);
	}

	protected function registerPendingUsage(string $reservationKey, int $userId): ?bool
	{
		return $this->getRestrictionManager()->registerPendingUsage($reservationKey, $userId);
	}

	private function getRestrictionManager(): RestrictionManager
	{
		return $this->restrictionManager;
	}
}
