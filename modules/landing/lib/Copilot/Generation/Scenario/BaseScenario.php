<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Step\Base\IStep;
use Bitrix\Landing\Metrika;

abstract class BaseScenario implements IScenario
{
	/**
	 * @var array<int, IStep>|null
	 */
	private ?array $mapCache = null;

	/**
	 * Build scenario map once per scenario instance.
	 *
	 * @return array<int, IStep>
	 */
	abstract protected function buildMap(): array;

	public function getMap(): array
	{
		if ($this->mapCache === null)
		{
			$this->mapCache = $this->buildMap();
		}

		return $this->mapCache;
	}

	abstract public function getQuotaCalculateStep(): ?int;

	/**
	 * Step whose failure means the request was refused on its topic rather than failed technically.
	 * Null when the scenario moderates nothing, so such a failure can never be read as a refusal.
	 */
	public function getModerationStepId(): ?int
	{
		return null;
	}

	abstract public function getAnalyticCategory(): Metrika\Categories;

	/**
	 * @inheritdoc
	 */
	public function isAnalyticStartEnabled(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function onGenerationError(Generation $generation, GenerationException $e): void
	{
	}

	/**
	 * Called once a run has ended the generation for good - any throwable, not only a generation error,
	 * whose Error mark has reached the database, so no executor is going to continue the generation.
	 * Not called for the time budget exit nor for a failure the retry agent resumes.
	 *
	 * Runs before the failure is saved, so an implementation must not throw: an exception here would cost
	 * the run the record of what happened. The generation guards the call anyway.
	 */
	public function onTerminalFailure(Generation $generation, \Throwable $e): void
	{
	}

	public function getAsyncRelations(): ?array
	{
		return null;
	}

	/**
	 * @inheritdoc
	 */
	public function getFirstStepId(): ?int
	{
		foreach ($this->getMap() as $id => $step)
		{
			return $id;
		}

		return null;
	}

	/**
	 * @inheritdoc
	 */
	public function getNextStepId(int $stepId): ?int
	{
		$useNext = false;
		foreach ($this->getMap() as $id => $step)
		{
			if ($useNext)
			{
				return $id;
			}

			if ($id === $stepId)
			{
				$useNext = true;
			}
		}

		return null;
	}

	public function isLastStep(int $stepId): bool
	{
		$ids = array_keys($this->getMap());
		$lastId = array_pop($ids);
		if (!$lastId)
		{
			return false;
		}

		return $lastId === $stepId;
	}

	/**
	 * @inheritdoc
	 */
	public function checkStep(?int $stepId): bool
	{
		if ($stepId === null)
		{
			return true;
		}

		return array_key_exists($stepId, $this->getMap());
	}
}
