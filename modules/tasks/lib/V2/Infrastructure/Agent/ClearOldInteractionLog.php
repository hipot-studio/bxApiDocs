<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Update\AgentInterface;
use Bitrix\Tasks\Update\AgentTrait;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use Bitrix\Tasks\V2\Public\Command\Interaction\ClearOldInteractionLogCommand;

final class ClearOldInteractionLog implements AgentInterface
{
	use AgentTrait;

	// Light and regular instead of one heavy daily burst: an idle tick costs a single
	// empty range scan over the CREATED_AT index, and the per-run batch ceiling still
	// drains far above the throttled write rate.
	public static function getInterval(): int
	{
		return 60 * 20; // 20 minutes
	}

	public static function execute(): string
	{
		if (!RankingConfig::isEnabled())
		{
			return self::agentName();
		}

		$cutoff = (new DateTime())->add('-' . RankingConfig::getRelevanceWindowDays() . ' days');

		// The per-user cap is TrimUserInteractionLog.
		(new ClearOldInteractionLogCommand($cutoff))->run();

		return self::agentName();
	}

	/**
	 * Without the leading slash: the return value replaces b_agent.NAME, and a name
	 * drifting from install/migrations/agents.php would let a reinstall add a second
	 * purge agent.
	 */
	private static function agentName(): string
	{
		return self::getAgentName(false);
	}
}
