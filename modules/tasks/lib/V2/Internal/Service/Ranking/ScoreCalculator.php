<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Ranking;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Repository\InteractionLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionLogCleanupTrigger;

/**
 * Personal relevance score aggregator: reads the requesting user's interaction
 * journal for the relevance window in a single indexed query and aggregates
 * weight * freshness per task in PHP, without a score expression in SQL.
 *
 * Strictly per-user: only the requesting user's own events feed the score.
 */
final class ScoreCalculator
{
	private const WEIGHT_OPEN = 1;
	private const WEIGHT_ACTION = 5; // create / update / comment
	private const WEIGHT_RELATION = 10;
	private const WEIGHT_RELATION_SAME_KIND = 20;

	private const FRESHNESS_RECENT_DAYS = 7;
	private const FRESHNESS_MID_DAYS = 14;

	private const FRESHNESS_RECENT = 1.0;
	private const FRESHNESS_MID = 0.6;
	private const FRESHNESS_OLD = 0.3;

	private const SECONDS_IN_DAY = 86400;

	/**
	 * Request-scoped memoization of the aggregated map, keyed by user and selector
	 * kind: a hit that ranks several times pays for one journal scan per kind. Only
	 * the folded map is kept, never the rows it came from.
	 *
	 * @var array<string, array<int, array{score: float, lastAt: int}>>
	 */
	private array $scoreCache = [];

	/**
	 * Users the trim was already scheduled for during this hit.
	 *
	 * @var array<int, true>
	 */
	private array $trimScheduled = [];

	public function __construct(
		private readonly InteractionLogRepositoryInterface $repository,
		private ?InteractionLogCleanupTrigger $cleanupTrigger = null,
	)
	{
	}

	/**
	 * @return array<int, array{score: float, lastAt: int}>
	 *     Map taskId => aggregated score and the timestamp of the last event.
	 */
	public function calculate(int $userId, ?RelationKind $selectorKind, DateTime $now): array
	{
		$cacheKey = $userId . '|' . ($selectorKind?->value ?? '');

		return $this->scoreCache[$cacheKey] ??= $this->aggregate($userId, $selectorKind, $now);
	}

	/**
	 * @return array<int, array{score: float, lastAt: int}>
	 */
	private function aggregate(int $userId, ?RelationKind $selectorKind, DateTime $now): array
	{
		$nowTs = $now->getTimestamp();
		$cutoff = DateTime::createFromTimestamp(
			$nowTs - (RankingConfig::getRelevanceWindowDays() * self::SECONDS_IN_DAY)
		);

		$score = [];
		$lastAt = [];
		$rowCount = 0;

		foreach ($this->repository->findEventsForWindow($userId, $cutoff) as $row)
		{
			++$rowCount;

			$taskId = (int)$row['TASK_ID'];

			/** @var DateTime $createdAt */
			$createdAt = $row['CREATED_AT'];
			$createdTs = $createdAt->getTimestamp();

			$weight = $this->weight((string)$row['EVENT_TYPE'], $row['RELATION_KIND'] ?? null, $selectorKind);
			$freshness = $this->freshness($createdTs, $nowTs);

			$score[$taskId] = ($score[$taskId] ?? 0.0) + ($weight * $freshness);
			$lastAt[$taskId] = max($lastAt[$taskId] ?? 0, $createdTs);
		}

		$this->scheduleTrimOnFullWindow($userId, $rowCount);

		$result = [];
		foreach ($score as $taskId => $value)
		{
			$result[$taskId] = [
				'score' => $value,
				'lastAt' => $lastAt[$taskId],
			];
		}

		return $result;
	}

	/**
	 * A full window read means the user is at or over the events limit, which is also
	 * the storage cap the trim restores - one number, so the two cannot drift apart.
	 */
	private function scheduleTrimOnFullWindow(int $userId, int $rowCount): void
	{
		if ($rowCount < RankingConfig::getEventsLimit() || isset($this->trimScheduled[$userId]))
		{
			return;
		}

		$this->trimScheduled[$userId] = true;

		$this->cleanupTrigger()->onFullWindowRead($userId);
	}

	private function cleanupTrigger(): InteractionLogCleanupTrigger
	{
		return $this->cleanupTrigger ??= new InteractionLogCleanupTrigger();
	}

	private function weight(string $eventType, ?string $relationKind, ?RelationKind $selectorKind): int
	{
		if ($eventType === EventType::Open->value)
		{
			return self::WEIGHT_OPEN;
		}

		if (
			$eventType === EventType::Create->value
			|| $eventType === EventType::Update->value
			|| $eventType === EventType::Comment->value
		)
		{
			return self::WEIGHT_ACTION;
		}

		if ($eventType === EventType::Relation->value)
		{
			return $this->isSameKindBoost($relationKind, $selectorKind)
				? self::WEIGHT_RELATION_SAME_KIND
				: self::WEIGHT_RELATION;
		}

		return 0;
	}

	/**
	 * The boost applies only to the parent and related selectors: subtask and gantt
	 * always use the base weight.
	 */
	private function isSameKindBoost(?string $relationKind, ?RelationKind $selectorKind): bool
	{
		if (
			$selectorKind !== RelationKind::Parent
			&& $selectorKind !== RelationKind::Related
		)
		{
			return false;
		}

		return $relationKind === $selectorKind->value;
	}

	private function freshness(int $createdTs, int $nowTs): float
	{
		if ($createdTs >= $nowTs - (self::FRESHNESS_RECENT_DAYS * self::SECONDS_IN_DAY))
		{
			return self::FRESHNESS_RECENT;
		}

		if ($createdTs >= $nowTs - (self::FRESHNESS_MID_DAYS * self::SECONDS_IN_DAY))
		{
			return self::FRESHNESS_MID;
		}

		return self::FRESHNESS_OLD;
	}
}
