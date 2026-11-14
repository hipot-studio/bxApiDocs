<?php

namespace Bitrix\Crm\Search;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @internal except flush()
 *
 * Builds the search content of the entities marked during the hit once per entity, in a background job
 * of the hit, from the state of the entity in the database at the time of the build.
 *
 * A mark made while the job runs schedules the job once more, for the next pass of the queue. The runs
 * of the job are limited: the job runs only from the single terminate() of the process, so the limit
 * per process is the limit per pass of terminate(). Over the limit the marks are dropped and reported.
 *
 * Only a snapshot of the job and flush() take the marks: an entity deleted after its mark, or whose add is
 * rolled back, is built for nothing, as the build finds no entity and writes nothing.
 */
final class DeferredSearchIndexer
{
	public const JOB_PRIORITY = Application::JOB_PRIORITY_LOW + 25;
	public const MAX_RETRIES = 10;
	public const MAX_PENDING = 10000;
	public const MAX_LOGGED_KEYS = 100;

	private const KIND_CONTENT = 'content';

	private \Closure $builderFactory;
	private \Closure $jobScheduler;
	private LoggerInterface $logger;

	/** @var array<int, array<int, array<string, true>>> entityTypeId => entityId => kind of index => true */
	private array $pending = [];
	private int $pendingCount = 0;
	private bool $isScheduled = false;
	private int $jobRuns = 0;
	private bool $isExhausted = false;
	private bool $isOverflowReported = false;

	/**
	 * @param \Closure(int): SearchContentBuilder|null $builderFactory
	 * @param \Closure(\Closure, int): mixed|null $jobScheduler gets the job and its priority
	 */
	public function __construct(
		?\Closure $builderFactory = null,
		?\Closure $jobScheduler = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->builderFactory = $builderFactory
			?? static fn(int $entityTypeId): SearchContentBuilder => SearchContentBuilderFactory::create($entityTypeId)
		;
		$this->jobScheduler = $jobScheduler
			?? static fn(\Closure $job, int $priority) => Application::getInstance()->addBackgroundJob(
				$job,
				[],
				$priority,
			)
		;
		$this->logger = $logger ?? Container::getInstance()->getLogger('Default');
	}

	public function mark(int $entityTypeId, int $entityId): void
	{
		if ($entityId <= 0 || $this->isExhausted)
		{
			return;
		}

		if (isset($this->pending[$entityTypeId][$entityId]))
		{
			$this->pending[$entityTypeId][$entityId][self::KIND_CONTENT] = true;
		}
		elseif ($this->pendingCount >= self::MAX_PENDING)
		{
			$this->reportOverflow($entityTypeId, $entityId);

			return;
		}
		else
		{
			$this->pending[$entityTypeId][$entityId] = [self::KIND_CONTENT => true];
			$this->pendingCount++;
		}

		$this->ensureScheduled();
	}

	public function isMarked(int $entityTypeId, int $entityId): bool
	{
		return isset($this->pending[$entityTypeId][$entityId]);
	}

	/**
	 * Builds everything marked right away, including the marks made by the builds themselves, up to the same
	 * number of rounds a pass of the job has. Does not spend the limit of the job and leaves the scheduled job
	 * to find nothing. Meant for tests, where the background jobs of the hit never run.
	 */
	public function flush(): void
	{
		for ($round = 0; $round <= self::MAX_RETRIES && !empty($this->pending); $round++)
		{
			$this->buildSnapshot($this->takeSnapshot());
		}

		if (!empty($this->pending))
		{
			$this->dropPending('{class}: entities are marked again by every flush round. Marks are dropped');
		}
	}

	private function ensureScheduled(): void
	{
		if ($this->isScheduled)
		{
			return;
		}

		($this->jobScheduler)(fn() => $this->run(), self::JOB_PRIORITY);
		$this->isScheduled = true;
	}

	private function run(): void
	{
		$this->isScheduled = false;

		if (empty($this->pending))
		{
			return;
		}

		if ($this->jobRuns > self::MAX_RETRIES)
		{
			$this->isExhausted = true;
			$this->dropPending(
				'{class}: the background build has run too many times. Possible infinite recursion. Marks are dropped'
			);

			return;
		}

		$this->jobRuns++;
		$this->buildSnapshot($this->takeSnapshot());
	}

	/**
	 * @return array<int, array<int, array<string, true>>>
	 */
	private function takeSnapshot(): array
	{
		$snapshot = $this->pending;
		$this->pending = [];
		$this->pendingCount = 0;
		$this->isOverflowReported = false;

		return $snapshot;
	}

	/**
	 * @param array<int, array<int, array<string, true>>> $snapshot
	 */
	private function buildSnapshot(array $snapshot): void
	{
		foreach ($snapshot as $entityTypeId => $entities)
		{
			$entityIds = array_keys($entities);

			try
			{
				$builder = ($this->builderFactory)($entityTypeId);
			}
			catch (\Throwable $exception)
			{
				foreach ($entityIds as $entityId)
				{
					$this->logBuildFailure($entityTypeId, $entityId, $exception);
				}

				continue;
			}

			foreach ($entityIds as $entityId)
			{
				try
				{
					$builder->build($entityId, ['checkExist' => true]);
				}
				catch (\Throwable $exception)
				{
					$this->logBuildFailure($entityTypeId, $entityId, $exception);
				}
			}
		}
	}

	private function logBuildFailure(int $entityTypeId, int $entityId, \Throwable $exception): void
	{
		$this->log(
			LogLevel::ERROR,
			'{class}: failed to build the search content of {entityTypeId}:{entityId}. {exception}',
			[
				'class' => self::class,
				'entityTypeId' => $entityTypeId,
				'entityId' => $entityId,
				'exception' => $exception,
			],
		);
	}

	private function reportOverflow(int $entityTypeId, int $entityId): void
	{
		if ($this->isOverflowReported)
		{
			return;
		}

		$this->isOverflowReported = true;
		$this->log(
			LogLevel::CRITICAL,
			'{class}: too many entities are marked. New marks are dropped',
			[
				'class' => self::class,
				'maxPending' => self::MAX_PENDING,
				'entityTypeId' => $entityTypeId,
				'entityId' => $entityId,
			],
		);
	}

	private function dropPending(string $message): void
	{
		$dropped = $this->pendingCount;
		$remaining = [];
		$room = self::MAX_LOGGED_KEYS;
		foreach ($this->pending as $entityTypeId => $entities)
		{
			if ($room <= 0)
			{
				break;
			}

			$remaining[$entityTypeId] = array_slice(array_keys($entities), 0, $room);
			$room -= count($remaining[$entityTypeId]);
		}

		$this->pending = [];
		$this->pendingCount = 0;

		$this->log(
			LogLevel::CRITICAL,
			$message,
			[
				'class' => self::class,
				'dropped' => $dropped,
				'remaining' => $remaining,
			],
		);
	}

	/**
	 * The logger may write to the database of the hit and fail there. Its failure must neither leave run()
	 * or flush() nor stop the build of the rest, and there is nowhere else to report it.
	 */
	private function log(string $level, string $message, array $context): void
	{
		try
		{
			$this->logger->log($level, $message, $context);
		}
		catch (\Throwable)
		{
		}
	}
}
