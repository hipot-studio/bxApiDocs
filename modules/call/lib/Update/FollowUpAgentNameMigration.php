<?php

namespace Bitrix\Call\Update;

use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;

/**
 * Brings the follow-up waits armed before this release into the current form of the agent name.
 *
 * A row of the older form runs itself and the core rewrites its name on that first run, so the name
 * alone would need no migration. What does not heal itself is the interval: the core computes the
 * next run from the AGENT_INTERVAL already stored on the row, and the version that armed these
 * waits stored six hours where this release means one - inside a window of twelve hours counted
 * from the end of the call. Left alone, a wait standing since before the update would spend two
 * checks where the release gives it twelve, on exactly the calls the release exists to rescue.
 *
 * What each row is turned into, and why the counter of spent checks and NEXT_EXEC are carried over
 * untouched, is written where the work is done {@see CallAIService::migrateLegacyExpectationAgents()}.
 * This class is only the batching around it: the number of such rows is bounded by nothing but the
 * history of the portal, so they are taken in steps, and the query of every step reads what the
 * previous ones have not converted yet.
 *
 * @internal
 */
final class FollowUpAgentNameMigration extends Stepper
{
	private const ROWS_PER_STEP = 200;

	protected static $moduleId = 'call';

	public function execute(array &$option): bool
	{
		if (!Loader::includeModule('call'))
		{
			return self::FINISH_EXECUTION;
		}

		$touched = CallAIService::getInstance()->migrateLegacyExpectationAgents(self::ROWS_PER_STEP);
		$done = $touched < self::ROWS_PER_STEP;

		// The total is unknown until the last step finds nothing left, so while there is more to do
		// the bar is told there is at least one more step ahead of what is already converted.
		$option['steps'] = (int)($option['steps'] ?? 0) + $touched;
		$option['count'] = $option['steps'] + ($done ? 0 : self::ROWS_PER_STEP);

		return $done ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}
}
