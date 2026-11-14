<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class EditSessionAdmissionTicket
{
	public function __construct(private readonly string $outcome)
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
}
