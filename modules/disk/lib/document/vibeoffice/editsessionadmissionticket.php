<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;

/**
 * Outcome of {@see EditSessionAdmission::admit()} together with the slot it reserved.
 *
 * Resolved exactly once: commit() binds the reservation to the document key of the session that
 * was actually opened, cancel() gives the slot back. The caller runs cancel() from a finally
 * block, so a session that never opened never keeps a slot.
 */
final class EditSessionAdmissionTicket
{
	private bool $resolved = false;

	public function __construct(
		private readonly string $outcome,
		private readonly ?RestrictionManager $restrictionManager = null,
		private readonly ?string $reservationKey = null,
		private readonly ?int $userId = null,
	)
	{
	}

	public function isAllowed(): bool
	{
		return $this->outcome === EditSessionAdmission::OUTCOME_ALLOWED;
	}

	public function getOutcome(): string
	{
		return $this->outcome;
	}

	public function commit(string $documentKey): void
	{
		if ($this->resolved)
		{
			return;
		}

		if ($this->hasReservation())
		{
			$this->restrictionManager->bindPendingUsage($this->reservationKey, $documentKey, $this->userId);
		}

		$this->resolved = true;
	}

	public function cancel(): void
	{
		if ($this->resolved)
		{
			return;
		}

		if ($this->hasReservation())
		{
			$this->restrictionManager->cancelPendingUsage($this->reservationKey, $this->userId);
		}

		$this->resolved = true;
	}

	private function hasReservation(): bool
	{
		return $this->restrictionManager !== null
			&& $this->reservationKey !== null
			&& $this->userId !== null
		;
	}
}
