<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

final readonly class StepContext
{
	public function __construct(
		private int $activityId,
		private int $userId,
		private string $scenarioName,
		private bool $isManualLaunch,
		private ?string $activityProvider = null,
		/**
		 * Additional context for step creation.
		 * Currently only `assessmentSettingsId` is set in production (from CallQualityAssessment controller).
		 * Other keys (storageTypeId, storageElementId, screeningTarget, targetEntityTypeId, targetEntityId)
		 * are reserved for future AutoLauncher migration to PipelineExecutor.
		 */
		private array $extra = [],
	)
	{
	}

	public function getActivityId(): int
	{
		return $this->activityId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getScenarioName(): string
	{
		return $this->scenarioName;
	}

	public function isManualLaunch(): bool
	{
		return $this->isManualLaunch;
	}

	public function getActivityProvider(): ?string
	{
		return $this->activityProvider;
	}

	public function getExtra(string $key, mixed $default = null): mixed
	{
		return $this->extra[$key] ?? $default;
	}

	public function withScenarioName(string $name): self
	{
		return new self(
			$this->activityId,
			$this->userId,
			$name,
			$this->isManualLaunch,
			$this->activityProvider,
			$this->extra,
		);
	}

	public function withActivityProvider(?string $provider): self
	{
		return new self(
			$this->activityId,
			$this->userId,
			$this->scenarioName,
			$this->isManualLaunch,
			$provider,
			$this->extra,
		);
	}

	public function withExtra(string $key, mixed $value): self
	{
		$extra = $this->extra;
		$extra[$key] = $value;

		return new self(
			$this->activityId,
			$this->userId,
			$this->scenarioName,
			$this->isManualLaunch,
			$this->activityProvider,
			$extra,
		);
	}
}
