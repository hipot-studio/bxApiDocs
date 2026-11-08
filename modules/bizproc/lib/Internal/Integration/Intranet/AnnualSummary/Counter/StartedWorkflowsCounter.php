<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary\Counter;

use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;

/**
 * How many workflows an employee has started within the period. The whole batch is counted by one
 * grouped query; the batch size belongs to the provider, not here.
 */
final class StartedWorkflowsCounter implements MetricCounterInterface
{
	public const METRIC = 'bizproc.started_workflows';

	private const COUNT_ALIAS = 'WORKFLOW_COUNT';

	public function getMetric(): string
	{
		return self::METRIC;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	public function countByUsers(array $userIds, Date $from, Date $to): array
	{
		$result = WorkflowStateTable::query()
			->addSelect('STARTED_BY')
			->addSelect(Query::expr()->setAlias(self::COUNT_ALIAS)->count('ID'))
			->whereIn('STARTED_BY', $userIds)
			->where('STARTED', '>=', $from)
			->where('STARTED', '<', $to)
			// States marked for deletion are told apart by ENTITY alone: MODULE_ID is nullable, so
			// `MODULE_ID != 'bizproc'` would silently drop every row where it is NULL.
			->where('ENTITY', '!=', WorkflowStateTable::ZOMBIE_DOCUMENT_ID[1])
			->setGroup(['STARTED_BY'])
			->exec()
		;

		$counts = [];
		while ($row = $result->fetch())
		{
			$total = (int)$row[self::COUNT_ALIAS];
			if ($total > 0)
			{
				$counts[(int)$row['STARTED_BY']] = $total;
			}
		}

		return $counts;
	}
}
