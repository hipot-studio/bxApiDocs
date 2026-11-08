<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

final readonly class LegacyRequirementRange
{
	public function __construct(
		private int $phaseGroupTypeId,
		private string $startPhaseId,
		private string $finishPhaseId,
	)
	{
	}

	public function getPhaseGroupTypeId(): int
	{
		return $this->phaseGroupTypeId;
	}

	public function getStartPhaseId(): string
	{
		return $this->startPhaseId;
	}

	public function getFinishPhaseId(): string
	{
		return $this->finishPhaseId;
	}
}
