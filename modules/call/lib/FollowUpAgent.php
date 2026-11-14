<?php

namespace Bitrix\Call;

use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Main\Type\Callback;

/**
 * Entry point of the agent waiting for the follow-up of a call, and nothing more than that: the
 * check itself lives in {@see CallAIService::expectCallAiTask()}.
 *
 * It exists to keep the name short. A scheduled agent is addressed by its name, and b_agent.NAME is
 * indexed by its first 100 characters only (`ix_agent_name`), so the call id has to fit inside them
 * or every lookup of an expectation reads every expectation of the portal instead of one row
 * {@see CallAIService::getExpectationAgentNamePattern()}. This entry point puts the call id at
 * character 91; the one of the previous version put it at 121.
 *
 * @internal
 */
final class FollowUpAgent
{
	/**
	 * @return Callback|null Next check of the call, or null when there is nothing left to wait for.
	 */
	public static function run(int $callId, int $repeat = 1): ?Callback
	{
		return CallAIService::expectCallAiTask($callId, $repeat);
	}
}
