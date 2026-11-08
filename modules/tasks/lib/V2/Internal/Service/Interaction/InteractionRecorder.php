<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Interaction;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Repository\InteractionLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RelationKind;
use Throwable;

/**
 * Records a personal interaction into the ranking journal.
 *
 * Guards run synchronously on the hit (feature flag, origin, request-scoped
 * dedup); targets are buffered and flushed by a single background job, so a mass
 * action costs one throttle-select and one insert per bucket, not per item.
 */
class InteractionRecorder
{
	/**
	 * Request-scoped dedup against repeated entries within one request, e.g.
	 * cascades re-entering UpdateService. Static, unlike the buffer below: it must
	 * hold even for a recorder constructed outside the container, while a buffer
	 * shared that way could be flushed twice.
	 *
	 * @var array<string, true>
	 */
	private static array $scheduled = [];

	/**
	 * Targets awaiting the flush, grouped by bucket (user, event type, relation
	 * kind, write moment).
	 *
	 * @var array<string, array{
	 *     userId: int,
	 *     taskIds: int[],
	 *     eventType: EventType,
	 *     relationKind: ?RelationKind,
	 *     createdAt: DateTime,
	 * }>
	 */
	private array $buffer = [];

	private bool $flushScheduled = false;

	public function __construct(
		private readonly InteractionLogRepositoryInterface $repository,
	)
	{
	}

	/**
	 * Flushes the buffer as the registered background job, or synchronously when
	 * there is no Application to defer to.
	 */
	public function __invoke(): void
	{
		$buckets = $this->buffer;
		$this->buffer = [];
		$this->flushScheduled = false;

		foreach ($buckets as $bucket)
		{
			$this->writeBatch(
				$bucket['userId'],
				$bucket['taskIds'],
				$bucket['eventType'],
				$bucket['relationKind'],
				$bucket['createdAt'],
			);
		}
	}

	public function record(
		int $userId,
		int $taskId,
		EventType $eventType,
		?RelationKind $relationKind,
		InteractionOrigin $origin,
	): void
	{
		if (!RankingConfig::isEnabled())
		{
			return;
		}

		if ($origin !== InteractionOrigin::Ui)
		{
			return;
		}

		if ($userId <= 0 || $taskId <= 0)
		{
			return;
		}

		$guardKey = $this->guardKey($userId, $taskId, $eventType, $relationKind);
		if (isset(self::$scheduled[$guardKey]))
		{
			return;
		}
		self::$scheduled[$guardKey] = true;

		$this->bufferTargets($userId, [$taskId], $eventType, $relationKind);
		$this->scheduleFlush();
	}

	/**
	 * Records the same event on several tasks at once (mass link add).
	 *
	 * @param int[] $taskIds
	 */
	public function recordBatch(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		InteractionOrigin $origin,
	): void
	{
		if (!RankingConfig::isEnabled())
		{
			return;
		}

		if ($origin !== InteractionOrigin::Ui)
		{
			return;
		}

		if ($userId <= 0)
		{
			return;
		}

		$targets = [];
		foreach ($taskIds as $taskId)
		{
			$taskId = (int)$taskId;
			if ($taskId <= 0)
			{
				continue;
			}

			$guardKey = $this->guardKey($userId, $taskId, $eventType, $relationKind);
			if (isset(self::$scheduled[$guardKey]))
			{
				continue;
			}
			self::$scheduled[$guardKey] = true;

			$targets[] = $taskId;
		}

		if ($targets === [])
		{
			return;
		}

		$this->bufferTargets($userId, $targets, $eventType, $relationKind);
		$this->scheduleFlush();
	}

	/**
	 * @param int[] $taskIds
	 */
	private function bufferTargets(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
	): void
	{
		$now = new DateTime();
		$bucketKey = implode('|', [
			$userId,
			$eventType->value,
			$relationKind?->value ?? '',
			$now->getTimestamp(),
		]);

		if (!isset($this->buffer[$bucketKey]))
		{
			$this->buffer[$bucketKey] = [
				'userId' => $userId,
				'taskIds' => [],
				'eventType' => $eventType,
				'relationKind' => $relationKind,
				'createdAt' => $now,
			];
		}

		foreach ($taskIds as $taskId)
		{
			$this->buffer[$bucketKey]['taskIds'][] = $taskId;
		}
	}

	private function scheduleFlush(): void
	{
		if ($this->flushScheduled)
		{
			return;
		}

		$application = Application::getInstance();
		if ($application === null)
		{
			$this->__invoke();

			return;
		}

		$this->flushScheduled = true;
		$application->addBackgroundJob(job: $this);
	}

	/**
	 * @param int[] $taskIds
	 */
	private function writeBatch(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		DateTime $now,
	): void
	{
		try
		{
			if (
				$eventType === EventType::Open
				&& $this->repository->hasRecentOpenForUser($userId, $this->openUserThreshold($now))
			)
			{
				return;
			}

			$threshold = $this->throttleThreshold($eventType, $now);

			$recent = array_flip(
				$this->repository->findTaskIdsWithRecentEvent($userId, $taskIds, $eventType, $relationKind, $threshold),
			);

			$toInsert = [];
			foreach ($taskIds as $taskId)
			{
				if (!isset($recent[$taskId]))
				{
					$toInsert[] = $taskId;
				}
			}

			if ($toInsert === [])
			{
				return;
			}

			$this->repository->appendBatch($userId, $toInsert, $eventType, $relationKind, $now);
		}
		catch (Throwable)
		{
			// Best-effort: a failed journal write must not break the originating action.
		}
	}

	private function openUserThreshold(DateTime $now): DateTime
	{
		return (clone $now)->add('-' . RankingConfig::getOpenUserThrottleWindow() . ' seconds');
	}

	private function throttleThreshold(EventType $eventType, DateTime $now): DateTime
	{
		$window = RankingConfig::getThrottleWindow($eventType);

		return (clone $now)->add('-' . $window . ' seconds');
	}

	private function guardKey(
		int $userId,
		int $taskId,
		EventType $eventType,
		?RelationKind $relationKind,
	): string
	{
		return implode('|', [
			$userId,
			$taskId,
			$eventType->value,
			$relationKind?->value ?? '',
		]);
	}
}
