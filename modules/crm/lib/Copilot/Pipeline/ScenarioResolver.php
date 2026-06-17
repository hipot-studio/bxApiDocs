<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Main\DI\ServiceLocator;

final class ScenarioResolver
{
	/**
	 * Resolves scenario name by completed operation's TYPE_ID and NEXT_TYPE_ID stored in QueueTable.
	 *
	 * When $scenarioHint is provided (extracted from AI engine context), verifies the hint
	 * matches the stored transition and returns it — even if the scenario is currently disabled,
	 * because the hint represents "what was originally launched".
	 *
	 * Falls back to transition matching for legacy/in-flight jobs where the hint is absent.
	 * Disabled scenarios are skipped during fallback transition matching.
	 *
	 * Disambiguation: NEXT_TYPE_ID differentiates scenarios sharing the same step.
	 * If the original scenario hint is unavailable, the first enabled registered match is returned.
	 *
	 * Migration: Legacy FULL scenario jobs have NEXT_TYPE_ID = null. Fallback handles this.
	 */
	public static function resolve(int $completedTypeId, ?int $nextTypeId, ?string $scenarioHint = null): ?string
	{
		// No continuation for explicitly-terminal steps
		if ($nextTypeId === 0)
		{
			return null;
		}

		$registry = ServiceLocator::getInstance()->get(ScenarioRegistry::class);

		// Scenario hint: if the original scenario name is known from AI engine context,
		// verify it matches the stored transition and return it regardless of isEnabled().
		if ($scenarioHint !== null)
		{
			$hintedScenario = $registry->getByName($scenarioHint);
			if ($hintedScenario !== null && self::scenarioMatchesTransition($hintedScenario, $completedTypeId, $nextTypeId))
			{
				return $hintedScenario->getId();
			}
		}

		// Primary resolution: match by (completedTypeId, nextTypeId) pair, skip disabled scenarios
		if ($nextTypeId !== null)
		{
			foreach ($registry->getAll() as $scenario)
			{
				if (!$scenario->isEnabled())
				{
					continue;
				}

				$steps = $scenario->getSteps();
				$stepTypeIds = array_map(static fn($class) => $class::TYPE_ID, $steps);
				$currentIndex = array_search($completedTypeId, $stepTypeIds, true);
				if ($currentIndex === false)
				{
					continue;
				}

				$expectedNextTypeId = $stepTypeIds[$currentIndex + 1] ?? 0;
				if ($expectedNextTypeId === $nextTypeId)
				{
					if (self::shouldSkipExplicitTransitionMatch($registry, $scenario, $completedTypeId, $nextTypeId))
					{
						continue;
					}

					return $scenario->getId();
				}
			}
		}

		// Fallback for NEXT_TYPE_ID = null — used by FULL scenario.
		//
		// Why: FULL and FILL_FIELDS share the Transcribe→Summarize transition, so NEXT_TYPE_ID
		// alone cannot distinguish them. Scenario::getNextTypeIdByScenario('full') intentionally
		// returns null to avoid ambiguity. This fallback resolves null as 'full' when enabled.
		//
		// This coupling exists because AutoLauncher launches operations via AIManager::launch*()
		// which uses getNextTypeIdByScenario(), not PipelineExecutor::setNextTypeIdOverride().
		// @todo: migrate AutoLauncher to PipelineExecutor — then this fallback is only needed
		// for in-flight jobs created before the migration, and can be removed after a transition period.
		// @see Scenario::getNextTypeIdByScenario()
		if ($nextTypeId === null)
		{
			$fullScenario = $registry->getByName(Scenario::FULL_SCENARIO);
			if ($fullScenario?->isEnabled())
			{
				$steps = $fullScenario->getSteps();
				$stepTypeIds = array_map(static fn($class) => $class::TYPE_ID, $steps);
				if (in_array($completedTypeId, $stepTypeIds, true))
				{
					return Scenario::FULL_SCENARIO;
				}
			}
		}

		return null;
	}

	/**
	 * Checks whether the given scenario would produce the observed (completedTypeId, nextTypeId) transition.
	 *
	 * FULL scenario uses null NEXT_TYPE_ID convention (Scenario::getNextTypeIdByScenario returns null),
	 * so it matches only when nextTypeId is null. Other scenarios use explicit NEXT_TYPE_ID values.
	 */
	private static function scenarioMatchesTransition(ScenarioInterface $scenario, int $completedTypeId, ?int $nextTypeId): bool
	{
		$steps = $scenario->getSteps();
		$stepTypeIds = array_map(static fn($class) => $class::TYPE_ID, $steps);
		$currentIndex = array_search($completedTypeId, $stepTypeIds, true);
		if ($currentIndex === false)
		{
			return false;
		}

		// Check NEXT_TYPE_ID convention for this scenario
		$scenarioNextTypeId = Scenario::getNextTypeIdByScenario($scenario->getId());
		if ($scenarioNextTypeId === null)
		{
			// Scenario uses null convention (e.g. FULL) — matches only null NEXT_TYPE_ID
			return $nextTypeId === null;
		}

		$expectedNextTypeId = $stepTypeIds[$currentIndex + 1] ?? 0;

		return $expectedNextTypeId === $nextTypeId;
	}

	/**
	 * FULL uses null NEXT_TYPE_ID in production, so explicit shared transitions remain ambiguous.
	 * Only transitions unique to FULL may be resolved via explicit NEXT_TYPE_ID.
	 */
	private static function shouldSkipExplicitTransitionMatch(
		ScenarioRegistry $registry,
		ScenarioInterface $scenario,
		int $completedTypeId,
		int $nextTypeId,
	): bool
	{
		return $scenario->getId() === Scenario::FULL_SCENARIO
			&& self::isTransitionSharedWithOtherScenario($registry, $scenario, $completedTypeId, $nextTypeId)
		;
	}

	private static function isTransitionSharedWithOtherScenario(
		ScenarioRegistry $registry,
		ScenarioInterface $scenario,
		int $completedTypeId,
		int $nextTypeId,
	): bool
	{
		foreach ($registry->getAll() as $otherScenario)
		{
			if ($otherScenario->getId() === $scenario->getId())
			{
				continue;
			}

			if (self::hasExplicitTransition($otherScenario, $completedTypeId, $nextTypeId))
			{
				return true;
			}
		}

		return false;
	}

	private static function hasExplicitTransition(
		ScenarioInterface $scenario,
		int $completedTypeId,
		int $nextTypeId,
	): bool
	{
		$steps = $scenario->getSteps();
		$stepTypeIds = array_map(static fn($class) => $class::TYPE_ID, $steps);
		$currentIndex = array_search($completedTypeId, $stepTypeIds, true);
		if ($currentIndex === false)
		{
			return false;
		}

		$expectedNextTypeId = $stepTypeIds[$currentIndex + 1] ?? 0;

		return $expectedNextTypeId === $nextTypeId;
	}
}
