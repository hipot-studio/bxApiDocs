<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Type\DateTime;

/**
 * A step check that can answer for a whole selection at once.
 *
 * The pass walks every candidate of the portal and asks the step about each of them, so a
 * check that goes to the database per user turns one hourly pass into as many queries as
 * there are employees. A checker that implements this reads its selection once, and every
 * isCompleted() of that selection is then answered from the result.
 *
 * Preloading is an optimisation, never a contract change: a checker that was not preloaded,
 * and a user outside the preloaded selection, keep the per-user route. Nothing may depend on
 * preload() having been called.
 */
interface BulkStepChecker extends StepChecker
{
	/**
	 * Reads the completion of the whole selection in one go.
	 *
	 * @param int[] $userIds the candidates the pass is about to walk
	 */
	public function preload(array $userIds, DateTime $sinceDate): void;
}
