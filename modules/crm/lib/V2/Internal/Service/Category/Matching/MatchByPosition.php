<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Stage\DefaultColorGenerator;
use Bitrix\Crm\V2\Internal\Entity\Category\StageData;

/**
 * The strategy of the AI assistant tool: a stage is the same one when it stands in the same place.
 *
 * The tool has no identifiers in its contract at all
 * ({@see \Bitrix\Crm\Integration\AiAssistant\Tools\CategoryUpdateStagesList}), so the n-th stage the
 * model sends becomes the n-th stage of the category the replacement owns, whatever that stage used
 * to be called. What the list does not reach is deleted, what it is longer than the category by is
 * created in front of the first final stage, and the final stages themselves are left where they are.
 *
 * This is the pass the AI scenario used to run itself
 * ({@see \Bitrix\Crm\Integration\AI\Function\Category\Stage\UpdateList}), moved here as it is: the
 * matching is a tool a model already talks to, so it is kept exactly - including the workaround of
 * {@see self::match()} below, which is the one thing here that does not follow from the domain.
 * Anything that looks like a bug in it is a product decision and a task of its own, never a fix made
 * in passing. What surrounds the pass did change on the way, and the scenario lists it.
 *
 * Nothing here reaches storage or permissions: input in, plan out.
 *
 * @internal
 */
final class MatchByPosition implements StageMatchingStrategy
{
	/**
	 * @param StageData[] $current
	 * @param PositionalStageInput[] $input the stages the model sent, in the order they must stand in
	 */
	public function match(array $current, array $input): StageChangePlan
	{
		// One generator per plan, the way the scenario made one per call: the colours are a fixed
		// sequence and a stage takes the next one of it only when neither the input nor the stage
		// itself carries a colour, so which stage ends up with which colour depends on the order the
		// pass asks in.
		$colors = new DefaultColorGenerator();

		$pending = array_values($input);

		/** @var array<string, StageData> stages to update, by identifier, in the order the pass reaches them */
		$toUpdate = [];
		$toAdd = [];
		$toDelete = [];

		// The stage the last input item landed on, as the plan leaves it and as the category has it
		// now. The workaround below is the only reader of either.
		$lastUpdated = null;
		$lastUpdatedStored = null;

		foreach ($current as $stage)
		{
			$isFinal = PhaseSemantics::isFinal($stage->semantics);

			if ($pending !== [] && !$isFinal)
			{
				$lastUpdated = self::renamed($stage, array_shift($pending), $colors);
				$lastUpdatedStored = $stage;
				$toUpdate[$stage->stageId] = $lastUpdated;

				continue;
			}

			if ($pending !== [] && $isFinal)
			{
				// The first final stage of the category is where the rest of the list goes in. Behind it
				// the boundary would not keep a stage in process semantics
				// ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}), and the pass never gets here twice: the
				// input is spent by the time the next final stage comes.
				foreach ($pending as $item)
				{
					$toAdd[] = self::created($stage, $item, $colors);
				}

				$pending = [];

				continue;
			}

			if (!$isFinal)
			{
				if ($stage->isSystem && $lastUpdated !== null)
				{
					// The workaround of the old scenario, kept exactly: a system stage the input no longer
					// reaches is not deleted - it is renamed into the stage that was updated last, and that
					// stage is deleted in its place. The category keeps as many stages as the model asked
					// for, and the system stage keeps existing under the name of another one.
					//
					// It lives here and neither in the core nor in StageGuard because "the stage updated
					// last" is a notion of this pass alone. Do not straighten it into a plain deletion:
					// StageGuard would then refuse the whole replacement, and a tool the model already uses
					// would start failing where it used to succeed.
					$toUpdate[$stage->stageId] = new StageData(
						$stage->stageId,
						$stage->categoryId,
						$lastUpdated->name,
						$lastUpdated->color,
						$stage->semantics,
						$stage->sort,
						$stage->isSystem,
					);

					unset($toUpdate[$lastUpdatedStored->stageId]);
					$toDelete[] = $lastUpdatedStored;

					continue;
				}

				$toDelete[] = $stage;

				continue;
			}

			// A final stage the input has nothing left for stays exactly as it is, which a plan says by
			// saying nothing about it.
		}

		return StageChangePlan::of(array_values($toUpdate), $toAdd, $toDelete);
	}

	/**
	 * An existing stage under the name the input gave it, in the place it already stands: matching by
	 * position never moves a stage, it only renames the one that is already there.
	 *
	 * The colour of the input wins, the stored one is next, and a stage that has neither is given the
	 * next colour of the generator - the order of the three is part of the ported behaviour.
	 */
	private static function renamed(
		StageData $stage,
		PositionalStageInput $item,
		DefaultColorGenerator $colors,
	): StageData
	{
		return new StageData(
			$stage->stageId,
			$stage->categoryId,
			$item->name,
			$item->color ?? ($stage->color ?: null) ?? $colors->generate(),
			$stage->semantics,
			$stage->sort,
			$stage->isSystem,
		);
	}

	/**
	 * A stage of the input the category has no stage for, placed right in front of $insertionPoint.
	 *
	 * It carries the position of that final stage rather than one below it: the core lays a created
	 * stage out ahead of a stored stage of the same position
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer::replace()}), and the numbers
	 * themselves do not survive the call anyway.
	 *
	 * Unlike a renamed stage, a created one always carries a colour: the old scenario asked the
	 * generator for one whenever the model sent none.
	 */
	private static function created(
		StageData $insertionPoint,
		PositionalStageInput $item,
		DefaultColorGenerator $colors,
	): StageData
	{
		return new StageData(
			'',
			$insertionPoint->categoryId,
			$item->name,
			$item->color ?? $colors->generate(),
			PhaseSemantics::PROCESS,
			$insertionPoint->sort,
			false,
		);
	}
}
