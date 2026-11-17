<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Replication;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodBucketer;

/**
 * Instance fact: `b_tasks` instances by `FORKED_BY_TEMPLATE_ID` (P2.T2).
 *
 * Instance creation dates are stored in server time and converted to the viewer's timezone
 * before bucketization - the same as the plan projection (AC-S-062), otherwise fact and plan
 * land in different buckets. Index `ix_b_tasks_forked_created_date`.
 */
class InstanceFactRepository
{
	public function __construct(
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
		private readonly InstanceBucketExpressionBuilder $bucketExpressionBuilder = new InstanceBucketExpressionBuilder(),
	)
	{
	}

	/**
	 * Fact counters per bucket, grouped by template.
	 *
	 * @param int[] $templateIds
	 * @return array<int, array<string, int>> [templateId => [bucketKey => count]]
	 */
	public function countByTemplateAndBucket(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		string $scale,
		int $tzOffset,
	): array
	{
		$result = [];
		foreach (
			$this->fetchBucketCounts(
				$viewerId,
				$templateIds,
				$from,
				$to,
				$scale,
				$tzOffset,
				groupByTemplate: true,
			) as $row
		)
		{
			$templateId = (int)$row['FORKED_BY_TEMPLATE_ID'];
			$key = (string)($row[InstanceBucketExpressionBuilder::FIELD_NAME] ?? '');
			if ($templateId <= 0 || $key === '')
			{
				continue;
			}

			$result[$templateId][$key] = (int)($row['COUNT'] ?? 0);
		}

		return $result;
	}

	/**
	 * Fact split per bucket into "completed" and "total", grouped by template
	 * (Q-2). "Completed" - forked instances with status {@see Status::COMPLETED};
	 * bucketized by creation date the same as the whole fact, so `completed <= total`,
	 * and `created = total - completed` (P2.T2). The green part of the cell stacked bar.
	 *
	 * @param int[] $templateIds
	 * @return array<int, array<string, array{total: int, completed: int}>>
	 */
	public function countFactByTemplateAndBucket(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		string $scale,
		int $tzOffset,
	): array
	{
		$result = [];
		foreach (
			$this->fetchBucketCounts(
				$viewerId,
				$templateIds,
				$from,
				$to,
				$scale,
				$tzOffset,
				groupByTemplate: true,
				groupByStatus: true,
			) as $row
		)
		{
			$templateId = (int)$row['FORKED_BY_TEMPLATE_ID'];
			$key = (string)($row[InstanceBucketExpressionBuilder::FIELD_NAME] ?? '');
			if ($templateId <= 0 || $key === '')
			{
				continue;
			}

			$result[$templateId][$key] ??= ['total' => 0, 'completed' => 0];
			$count = (int)($row['COUNT'] ?? 0);
			$result[$templateId][$key]['total'] += $count;

			if (self::isCompletedStatus($row['REAL_STATUS'] ?? null))
			{
				$result[$templateId][$key]['completed'] += $count;
			}
		}

		return $result;
	}

	/**
	 * The single instance-completion predicate: `STATUS === Status::COMPLETED` (=5).
	 * `SUPPOSEDLY_COMPLETED` (=4) is not counted as completed. The only comparison point
	 * for the fact - so that the cell bucket split and the `completed` flag of an agenda item
	 * do not diverge (P2.T1).
	 */
	public static function isCompletedStatus(mixed $status): bool
	{
		return (int)$status === Status::COMPLETED;
	}

	/**
	 * Fact counters by scope+window without per-row materialization (ADR section 6): one indexed
	 * pass over `ix_b_tasks_forked_created_date` grouped by STATUS, folded in PHP
	 * into "completed/total" by the same predicate as the bucket split (P1.T2). `created`
	 * is computed by the caller as `total - completed`.
	 *
	 * @param int[] $templateIds
	 * @return array{completed: int, total: int}
	 */
	public function countCompletedAndTotal(int $viewerId, array $templateIds, int $from, int $to): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $to < $from)
		{
			return ['completed' => 0, 'total' => 0];
		}

		$taskQuery = (new Provider\Query\TaskQuery($viewerId))
			->setSelect(['REAL_STATUS', 'COUNT'])
			->setWhere($this->buildWhere($templateIds, $from, $to))
			->setGroupBy(['STATUS'])
		;
		$rows = Provider\Query\TaskQueryBuilder::build($taskQuery)
			->exec()
			->fetchAll()
		;

		$completed = 0;
		$total = 0;
		foreach ($rows as $row)
		{
			$count = (int)($row['COUNT'] ?? 0);
			$total += $count;

			if (self::isCompletedStatus($row['REAL_STATUS'] ?? null))
			{
				$completed += $count;
			}
		}

		return ['completed' => $completed, 'total' => $total];
	}

	/**
	 * Total fact counters per bucket over all slice templates (for "Total").
	 *
	 * @param int[] $templateIds
	 * @return array<string, int> [bucketKey => count]
	 */
	public function countByBucket(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		string $scale,
		int $tzOffset,
	): array
	{
		$total = [];
		foreach (
			$this->fetchBucketCounts(
				$viewerId,
				$templateIds,
				$from,
				$to,
				$scale,
				$tzOffset,
			) as $row
		)
		{
			$key = (string)($row[InstanceBucketExpressionBuilder::FIELD_NAME] ?? '');
			if ($key !== '')
			{
				$total[$key] = (int)($row['COUNT'] ?? 0);
			}
		}

		return $total;
	}

	/**
	 * Fact instances for agenda: task id, parent, title, creation time.
	 * Cursor pagination by (CREATED_DATE, ID) - stable when new facts are inserted
	 * "today" during load-more (P3.T2). The direction sets the group:
	 *  - `past`: created < cursor, order DESC (into the past);
	 *  - `today`: window [from; to], order DESC.
	 *
	 * `completedFilter` cuts the stream by completeness at the SQL level so the keyset page
	 * fills only with matching facts (filter before pagination, P2.T2): `true` - completed only,
	 * `false` - in-progress only, `null` - no filter.
	 *
	 * @param int[] $templateIds
	 * @param array{ts:int, id:int}|null $cursor
	 * @return array<int, array{taskId:int, templateId:int, name:string, when:int, completed:bool, responsibleId:int}>
	 */
	public function getInstances(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		int $limit,
		?array $cursor = null,
		?bool $completedFilter = null,
	): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $limit < 1)
		{
			return [];
		}

		$where = $this->buildWhere($templateIds, $from, $to);
		if ($completedFilter !== null)
		{
			$where[($completedFilter ? '=' : '!=') . 'REAL_STATUS'] = Status::COMPLETED;
		}

		$taskQuery = (new Provider\Query\TaskQuery($viewerId))
			->setSelect([
				'ID',
				'TITLE',
				'FORKED_BY_TEMPLATE_ID',
				'CREATED_DATE',
				'REAL_STATUS',
				'RESPONSIBLE_ID',
			])
			->setWhere($where)
			->setOrder([
				'CREATED_DATE' => Provider\Query\TaskQueryInterface::SORT_DESC,
				'ID' => Provider\Query\TaskQueryInterface::SORT_DESC,
			])
			->setLimit($limit)
		;
		$query = Provider\Query\TaskQueryBuilder::build($taskQuery);

		if ($cursor !== null)
		{
			// (CREATED_DATE, ID) < (cursorTs, cursorId) - strict keyset for stable load-more
			$cursorDate = DateTime::createFromTimestamp($cursor['ts']);
			$query->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->where('CREATED_DATE', '<', $cursorDate)
					->where(
						Query::filter()
							->where('CREATED_DATE', $cursorDate)
							->where('ID', '<', $cursor['id'])
					)
			);
		}

		$result = [];
		foreach ($query->exec()->fetchAll() as $row)
		{
			$ts = $this->toTimestamp($row['CREATED_DATE'] ?? null);
			if ($ts === null)
			{
				continue;
			}

			$result[] = [
				'taskId' => (int)$row['ID'],
				'templateId' => (int)$row['FORKED_BY_TEMPLATE_ID'],
				'name' => (string)($row['TITLE'] ?? ''),
				'when' => $ts,
				'completed' => self::isCompletedStatus($row['REAL_STATUS'] ?? null),
				'responsibleId' => (int)($row['RESPONSIBLE_ID'] ?? 0),
			];
		}

		return $result;
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchBucketCounts(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		string $scale,
		int $tzOffset,
		bool $groupByTemplate = false,
		bool $groupByStatus = false,
	): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		$keys = $this->bucketer->buildBucketKeys($scale, $from, $to, $tzOffset);
		if ($templateIds === [] || $keys === [])
		{
			return [];
		}

		$bucketField = $this->bucketExpressionBuilder->build(
			Application::getConnection()->getSqlHelper(),
			$scale,
			$keys,
			$tzOffset,
		);
		$select = [$bucketField, 'COUNT'];
		$groupBy = [InstanceBucketExpressionBuilder::FIELD_NAME];
		if ($groupByTemplate)
		{
			$select[] = 'FORKED_BY_TEMPLATE_ID';
			$groupBy[] = 'FORKED_BY_TEMPLATE_ID';
		}
		if ($groupByStatus)
		{
			$select[] = 'REAL_STATUS';
			$groupBy[] = 'STATUS';
		}

		$taskQuery = (new Provider\Query\TaskQuery($viewerId))
			->setSelect($select)
			->setWhere($this->buildWhere($templateIds, $from, $to))
			->setGroupBy($groupBy)
		;

		return Provider\Query\TaskQueryBuilder::build($taskQuery)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int[] $templateIds
	 */
	private function buildWhere(array $templateIds, int $from, int $to): array
	{
		return [
			'@FORKED_BY_TEMPLATE_ID' => $templateIds,
			'>=CREATED_DATE' => DateTime::createFromTimestamp($from),
			'<=CREATED_DATE' => DateTime::createFromTimestamp($to),
		];
	}

	private function toTimestamp(mixed $createdDate): ?int
	{
		if ($createdDate instanceof DateTime)
		{
			return $createdDate->getTimestamp();
		}

		if (is_string($createdDate) && $createdDate !== '')
		{
			$ts = MakeTimeStamp($createdDate);

			return $ts > 0 ? (int)$ts : null;
		}

		return null;
	}
}
