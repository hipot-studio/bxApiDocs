<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Exception\UserAnnualSummaryException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

if (!Loader::includeModule('intranet'))
{
	return;
}

class MostProductiveMonthSummaryProvider implements SummaryProviderInterface
{
	public const TASK_PROVIDER_ID = 'tasks.most_productive_month';
	public const COMPLETED_TASKS_ID = 'tasks.most_productive_month_completed_tasks';
	public const TOTAL_COMPLETED_TASKS_ID = 'tasks.completed_tasks';
	public const USER_LIMIT = 500;
	private const LOGGER_ID = 'tasks.annual_summary_provider';

	private int $lastUserId = 0;
	private readonly LoggerInterface $logger;

	public function __construct(
		protected readonly Date $from,
		protected readonly Date $to,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	public function getId(): string
	{
		return self::TASK_PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_LIMIT;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function provide(array $userIds): EntityCollection
	{
		$collection = $this->createCollection();
		if (empty($userIds))
		{
			return $collection;
		}

		$userIds = array_map(static fn ($userId): int => (int)$userId, $userIds);
		$lastUserId = max($userIds);
		$best = [];
		$totalCounts = [];
		foreach ($this->readMonthRows($userIds) as $row)
		{
			$userId = (int)$row['RESPONSIBLE_ID'];
			$month = (int)$row['CALENDAR_MONTH'];
			$count = (int)$row['TASKS_COUNT'];
			$totalCounts[$userId] = ($totalCounts[$userId] ?? 0) + $count;
			$current = $best[$userId] ?? null;
			if (
				$current === null
				|| $count > $current['count']
				|| ($count === $current['count'] && $month < $current['month'])
			)
			{
				$best[$userId] = [
					'month' => $month,
					'count' => $count,
				];
			}
		}

		foreach ($userIds as $userId)
		{
			$month = $best[$userId]['month'] ?? 0;
			$count = $best[$userId]['count'] ?? 0;
			$total = $totalCounts[$userId] ?? 0;
			try
			{
				$summaries = $this->createUserSummaries($userId, $month, $count, $total);
			}
			catch (UserAnnualSummaryException $exception)
			{
				if ($exception->getUserId() !== $userId)
				{
					throw $exception;
				}

				$this->logger->error('Failed to provide annual summary values for user.', [
					'providerId' => $this->getId(),
					'userId' => $userId,
					'exception' => $exception,
				]);

				continue;
			}

			$this->validateUserSummaries($summaries, $userId, $month, $count, $total);
			$collection->add($summaries[0]);
			$collection->add($summaries[1]);
			$collection->add($summaries[2]);
		}

		$this->lastUserId = $lastUserId;

		return $collection;
	}

	/**
	 * @return iterable<array{RESPONSIBLE_ID: int|string, CALENDAR_MONTH: int|string, TASKS_COUNT: int|string}>
	 */
	protected function readMonthRows(array $userIds): iterable
	{
		$query = TaskTable::query()
			->setCustomBaseTableAlias('tasks_pm')
			->addSelect('RESPONSIBLE_ID')
			->addSelect(Query::expr()->setAlias('TASKS_COUNT')->count('ID'))
			->whereIn('RESPONSIBLE_ID', $userIds)
			->where('STATUS', Status::COMPLETED)
			->where('CLOSED_DATE', '>=', $this->from)
			->where('CLOSED_DATE', '<', $this->to)
		;

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$monthExpression = str_replace(
			'__PM_DATE__',
			'%s',
			str_replace('%', '%%', $sqlHelper->formatDate('MM', '__PM_DATE__')),
		);

		$result = $query
			->registerRuntimeField(new ExpressionField('CALENDAR_MONTH', $monthExpression, ['CLOSED_DATE']))
			->addSelect('CALENDAR_MONTH')
			->setGroup(['RESPONSIBLE_ID', 'CALENDAR_MONTH'])
			->exec()
		;

		while ($row = $result->fetch())
		{
			yield $row;
		}
	}

	/**
	 * @return array{0: Summary, 1: Summary, 2: Summary}
	 */
	protected function createUserSummaries(int $userId, int $month, int $count, int $total): array
	{
		return [
			new Summary($userId, self::TASK_PROVIDER_ID, $month),
			new Summary($userId, self::COMPLETED_TASKS_ID, $count),
			new Summary($userId, self::TOTAL_COMPLETED_TASKS_ID, $total),
		];
	}

	protected function createCollection(): EntityCollection
	{
		return new EntityCollection();
	}

	private function validateUserSummaries(array $summaries, int $userId, int $month, int $count, int $total): void
	{
		if (array_keys($summaries) !== [0, 1, 2])
		{
			throw new UnexpectedValueException('Annual summary must contain all three metrics.');
		}

		$totals = [$month, $count, $total];
		$ids = [self::TASK_PROVIDER_ID, self::COMPLETED_TASKS_ID, self::TOTAL_COMPLETED_TASKS_ID];
		foreach ($summaries as $index => $summary)
		{
			if (
				!($summary instanceof Summary)
				|| $summary->getUserId() !== $userId
				|| $summary->getId() !== $ids[$index]
				|| $summary->getTotal() !== $totals[$index]
			)
			{
				throw new UnexpectedValueException('Invalid annual summary metrics.');
			}
		}
	}
}
