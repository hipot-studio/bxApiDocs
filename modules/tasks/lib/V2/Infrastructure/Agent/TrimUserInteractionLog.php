<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\InteractionLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use CAgent;

/**
 * Per-user, self-terminating agent that trims one user's interaction journal back
 * to the read-path limit. Registered on demand when the read path sees a full
 * window, it drains a bounded number of batches per run and reschedules itself
 * only while old rows remain.
 *
 * @uses TrimUserInteractionLog::execute
 */
final class TrimUserInteractionLog
{
	private const MAX_BATCHES_PER_RUN = 10;

	private const AGENT_INTERVAL = 60;

	/**
	 * @return bool true when the agent is scheduled, either already or by this call.
	 * The caller throttles on this answer, so a failed registration must not read as
	 * a success.
	 */
	public static function add(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$agentName = self::agentName($userId);

		$existing = CAgent::GetList([], ['MODULE_ID' => 'tasks', 'NAME' => $agentName])->Fetch();
		if (!empty($existing['ID']))
		{
			return true;
		}

		// AddAgent never throws on a rejected insert, so its return value is the only
		// signal of a failed registration.
		return (bool)CAgent::AddAgent($agentName, 'tasks', 'N', self::AGENT_INTERVAL);
	}

	public static function execute(int $userId): string
	{
		$userId = (int)$userId;
		if ($userId <= 0 || !RankingConfig::isEnabled())
		{
			return '';
		}

		// The read-path limit is the storage cap: rows below it are unreadable by
		// scoring, so the trim never changes a ranking.
		$hasMore = self::repository()->trimUserToCap(
			$userId,
			RankingConfig::getEventsLimit(),
			RankingConfig::getPurgeBatchSize(),
			self::MAX_BATCHES_PER_RUN,
		);

		// Re-run string keeps the agent alive; empty string self-deletes it.
		return $hasMore ? self::agentName($userId) : '';
	}

	private static function repository(): InteractionLogRepositoryInterface
	{
		return Container::getInstance()->get(InteractionLogRepositoryInterface::class);
	}

	private static function agentName(int $userId): string
	{
		return self::class . "::execute({$userId});";
	}
}
