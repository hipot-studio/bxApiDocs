<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\UI\DashboardOnboarding;

final class DashboardOnboardingConfig
{
	/**
	 * @param DashboardOnboardingStep[] $steps
	 */
	public function __construct(
		private readonly DashboardOnboardingMode $mode,
		private readonly array $steps = [],
		private readonly bool $canRestart = false,
	)
	{
	}

	public function getMode(): DashboardOnboardingMode
	{
		return $this->mode;
	}

	/**
	 * @return DashboardOnboardingStep[]
	 */
	public function getSteps(): array
	{
		return $this->steps;
	}

	public function canRestart(): bool
	{
		return $this->canRestart;
	}

	public function toArray(): array
	{
		return [
			'mode' => $this->mode->value,
			'steps' => array_map(static fn(DashboardOnboardingStep $step) => $step->value, $this->steps),
			'canRestart' => $this->canRestart,
		];
	}
}
