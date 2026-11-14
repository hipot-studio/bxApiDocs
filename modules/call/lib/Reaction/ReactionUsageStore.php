<?php

declare(strict_types=1);

namespace Bitrix\Call\Reaction;

/**
 * Per-user history of sent reactions - the input of the personalized quick row: how often each
 * reaction was sent and which ones were sent last.
 *
 * Every use is counted, but the user options are written at most once per flush interval: uses in
 * between wait in {@see ReactionUseBuffer}, and every read merges them in, so the row never lags behind.
 * Each option is a single map, so a write is a read-modify-write: two devices flushing at the very same
 * moment can lose one delta.
 *
 * Only the ids the picker shows are ever recorded.
 */
final class ReactionUsageStore
{
	// A category of its own rather than 'call': writing an option drops the user option cache of the
	// whole category, and the call settings read on every bootstrap live in 'call'.
	private const OPTION_CATEGORY = 'call_reactions';
	private const COUNTERS_OPTION = 'reactions_frequency';
	private const RECENT_OPTION = 'reactions_recent';

	// The client lifts at most a whole quick row of recent reactions; more would never be shown.
	private const RECENT_LIMIT = 6;
	private const FLUSH_INTERVAL_SECONDS = 10;

	public function __construct(
		private readonly ReactionUseBuffer $buffer = new ReactionUseBuffer(),
		private readonly int $flushIntervalSeconds = self::FLUSH_INTERVAL_SECONDS,
	)
	{
	}

	/**
	 * @return array{counters: array<string, int>, recent: string[]} recent ids go from the oldest to the newest
	 */
	public function read(int $userId): array
	{
		if ($userId < 1)
		{
			return ['counters' => [], 'recent' => []];
		}

		$counters = $this->readStoredCounters($userId);
		$recent = $this->readStoredRecent($userId);

		$pending = $this->buffer->read($userId);
		if ($pending !== null)
		{
			$counters = $this->addCounters($counters, $pending['counters']);
			$recent = $this->pushRecent($recent, $pending['recent']);
		}

		return ['counters' => $counters, 'recent' => $recent];
	}

	public function record(int $userId, string $reactionId): void
	{
		if ($userId < 1 || !ReactionCatalog::isVisible($reactionId))
		{
			return;
		}

		$pending = $this->buffer->read($userId) ?? ['startedAt' => time(), 'counters' => [], 'recent' => []];
		$pending['counters'][$reactionId] = (int)($pending['counters'][$reactionId] ?? 0) + 1;
		$pending['recent'] = $this->pushRecent($pending['recent'], [$reactionId]);

		if ($this->isDue($pending))
		{
			$this->writeOut($userId, $pending);

			return;
		}

		$this->buffer->write($userId, $pending);
	}

	/**
	 * A series of reactions ends with no use to write it out, so a read of the quick row does it too.
	 */
	public function flushIfDue(int $userId): void
	{
		if ($userId < 1)
		{
			return;
		}

		$pending = $this->buffer->read($userId);
		if ($pending !== null && $this->isDue($pending))
		{
			$this->writeOut($userId, $pending);
		}
	}

	/**
	 * @param array{startedAt: int, counters: array<string, int>, recent: string[]} $pending
	 */
	private function isDue(array $pending): bool
	{
		return time() - $pending['startedAt'] >= $this->flushIntervalSeconds;
	}

	/**
	 * @param array{startedAt: int, counters: array<string, int>, recent: string[]} $pending
	 */
	private function writeOut(int $userId, array $pending): void
	{
		$counters = $this->addCounters($this->readStoredCounters($userId), $pending['counters']);
		$recent = $this->pushRecent($this->readStoredRecent($userId), $pending['recent']);

		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::COUNTERS_OPTION,
			$this->capToCountableSet($counters, $pending['recent']),
			false,
			$userId,
		);
		\CUserOptions::SetOption(self::OPTION_CATEGORY, self::RECENT_OPTION, $recent, false, $userId);

		$this->buffer->clear($userId);
	}

	/**
	 * @return array<string, int>
	 */
	private function readStoredCounters(int $userId): array
	{
		$stored = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::COUNTERS_OPTION, [], $userId);

		return is_array($stored) ? $this->addCounters([], $stored) : [];
	}

	/**
	 * @return string[]
	 */
	private function readStoredRecent(int $userId): array
	{
		$stored = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::RECENT_OPTION, [], $userId);

		return is_array($stored) ? $this->pushRecent([], $stored) : [];
	}

	/**
	 * Garbage in either map is skipped rather than breaking the row.
	 *
	 * @param array<string, int> $counters
	 * @param array<mixed, mixed> $added
	 * @return array<string, int>
	 */
	private function addCounters(array $counters, array $added): array
	{
		foreach ($added as $reactionId => $count)
		{
			if (is_string($reactionId) && is_numeric($count) && (int)$count > 0)
			{
				$counters[$reactionId] = ($counters[$reactionId] ?? 0) + (int)$count;
			}
		}

		return $counters;
	}

	/**
	 * Moves every sent id to the newest end, the way the client lifts it in the row.
	 *
	 * @param string[] $recent
	 * @param array<mixed, mixed> $sent
	 * @return string[]
	 */
	private function pushRecent(array $recent, array $sent): array
	{
		foreach ($sent as $reactionId)
		{
			if (!is_string($reactionId) || !ReactionCatalog::isVisible($reactionId))
			{
				continue;
			}

			$recent = array_values(array_diff($recent, [$reactionId]));
			$recent[] = $reactionId;
		}

		return array_slice($recent, -self::RECENT_LIMIT);
	}

	/**
	 * Keeps the map within the set of ids that can be counted at all.
	 *
	 * Recording on its own cannot overflow it, since only a visible id gets through, so this trims a map
	 * left by an earlier catalogue rather than a normal one. The ids just recorded stay in it whatever
	 * their count.
	 *
	 * @param array<string, int> $counters
	 * @param string[] $recordedReactionIds
	 * @return array<string, int>
	 */
	private function capToCountableSet(array $counters, array $recordedReactionIds): array
	{
		$limit = max(count(ReactionCatalog::getVisible()), 1);
		if (count($counters) <= $limit)
		{
			return $counters;
		}

		$recorded = array_intersect_key($counters, array_flip($recordedReactionIds));
		$others = array_diff_key($counters, $recorded);
		arsort($others);

		return $recorded + array_slice($others, 0, max($limit - count($recorded), 0), true);
	}
}
