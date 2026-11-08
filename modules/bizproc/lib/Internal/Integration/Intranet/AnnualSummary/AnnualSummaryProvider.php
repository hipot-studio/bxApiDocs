<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary;

use Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary\Counter\MetricCounterInterface;
use Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary\Counter\StartedWorkflowsCounter;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

// The class implements an interface of intranet, so without that module its autoload would be
// fatal. The other half of the pair is the class_exists() check in the event handler.
if (!Loader::includeModule('intranet'))
{
	return;
}

/**
 * Contribution of bizproc to the annual summary. Owns the exchange protocol of the intranet agent -
 * batch size, cursor, error isolation - and the decision whether to count at all, while the numbers
 * themselves come from the counters.
 */
final class AnnualSummaryProvider implements SummaryProviderInterface
{
	public const ID = 'bizproc.annual_summary';

	/**
	 * Employees per batch. Deliberately smaller than the hundred of tasks: a manager with
	 * automation set up starts thousands of workflows a year, and a batch of a hundred would read
	 * hundreds of thousands of rows within a single step of the agent.
	 */
	private const USER_ID_LIMIT = 25;

	private const LOGGER_ID = 'bizproc.annual_summary';

	/** @var MetricCounterInterface[] */
	private readonly array $counters;

	private int $lastUserId = 0;

	/**
	 * @param MetricCounterInterface[]|null $counters
	 */
	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?array $counters = null,
		private ?LoggerInterface $logger = null,
	)
	{
		$this->counters = $counters ?? [new StartedWorkflowsCounter()];
	}

	public function getId(): string
	{
		return self::ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	/**
	 * @param int[] $userIds
	 */
	public function provide(array $userIds): EntityCollection
	{
		$ids = $this->normalizeUserIds($userIds);

		// Set before any counting and regardless of its outcome: a failed count loses the numbers
		// of one batch, while a cursor left standing stops the summary of the whole portal. An
		// incomplete batch is not reset here - the agent does that itself on the same step.
		$this->lastUserId = empty($ids) ? 0 : max($ids);

		$collection = new EntityCollection();

		// The only check of the flag, and the reason the event handler hands the provider over
		// whatever it says: an id the agent has already saved with no provider behind it finishes
		// the run of the whole portal early. Switched off, no counter is asked and no table is read.
		if (empty($ids) || !FeatureFlag::isEnabled())
		{
			return $collection;
		}

		foreach ($this->counters as $counter)
		{
			foreach ($this->countSafely($counter, $ids) as $userId => $total)
			{
				if ($total > 0)
				{
					$collection->add(new AnnualSummaryValue($userId, $counter->getMetric(), $total));
				}
			}
		}

		return $collection;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	private function countSafely(MetricCounterInterface $counter, array $userIds): array
	{
		try
		{
			$counts = $counter->countByUsers($userIds, $this->from, $this->to);
		}
		catch (Throwable $exception)
		{
			// The cursor has already moved past this batch, so the log is the only trace left of
			// whose numbers are missing.
			$this->getLogger()->error('Failed to count an annual summary metric.', [
				'metric' => $counter->getMetric(),
				'firstUserId' => min($userIds),
				'lastUserId' => max($userIds),
				'from' => $this->from->format('Y-m-d'),
				'to' => $this->to->format('Y-m-d'),
				'exception' => $exception,
			]);

			return [];
		}

		return $this->normalizeCounts($counts);
	}

	/**
	 * @param int[] $userIds
	 * @return list<int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$ids = array_map(static fn ($userId): int => (int)$userId, $userIds);
		$ids = array_filter($ids, static fn (int $userId): bool => $userId > 0);

		return array_values(array_unique($ids));
	}

	/**
	 * @return array<int, int>
	 */
	private function normalizeCounts(array $counts): array
	{
		$normalizedCounts = [];
		foreach ($counts as $userId => $total)
		{
			$normalizedCounts[(int)$userId] = (int)$total;
		}

		return $normalizedCounts;
	}

	private function getLogger(): LoggerInterface
	{
		// The registry check is off on purpose: with it on the record appears only once an admin has
		// switched the logger on, and the failure lost that way is the first one. A journal of the portal
		// is still required - a defined LOG_FILENAME, or a logger of this id in the settings of a module.
		return $this->logger ??= (new LoggerFactory())
			->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)
			?? new NullLogger()
		;
	}
}
