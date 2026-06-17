<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Copilot\Pipeline\ScenarioInterface;

abstract class AbstractScenario implements ScenarioInterface
{
	public function canSkipTranscription(?string $activityProvider): bool
	{
		return false;
	}

	public function getStepsWithSkipTranscription(): array
	{
		return $this->getSteps(); // by default use base steps
	}

	public function resolveSteps(?string $activityProvider): array
	{
		if ($this->canSkipTranscription($activityProvider))
		{
			return $this->getStepsWithSkipTranscription();
		}

		return $this->getSteps();
	}

	public function getDisabledSliderCode(): ?string
	{
		return null;
	}
}
