<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

class EditSessionAdmission
{
	public const OUTCOME_ALLOWED = 'allowed';

	public function __construct()
	{
	}

	public function admit(int $objectId, int $userId, string $reservationKey): EditSessionAdmissionTicket
	{
		return new EditSessionAdmissionTicket(self::OUTCOME_ALLOWED);
	}
}
