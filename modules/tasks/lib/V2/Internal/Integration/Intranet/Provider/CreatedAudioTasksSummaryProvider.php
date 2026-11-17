<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\ScenarioTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\Task\Scenario;

if (!Loader::includeModule('intranet'))
{
	return;
}

class CreatedAudioTasksSummaryProvider extends AbstractAnnualSummaryProvider
{
	public const TASK_PROVIDER_ID = 'tasks.created_audio_tasks';
	public const USER_LIMIT = 5000;

	public function getId(): string
	{
		return self::TASK_PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_LIMIT;
	}

	protected function countByUsers(array $userIds): array
	{
		$query = TaskTable::query()
			->addSelect('CREATED_BY')
			->addSelect(Query::expr()->setAlias('TASKS_COUNT')->count('ID'))
			->whereIn('CREATED_BY', $userIds)
			->where('CREATED_DATE', '>=', $this->from)
			->where('CREATED_DATE', '<', $this->to)
			->setGroup(['CREATED_BY'])
		;

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$taskAlias = $sqlHelper->quote($query->getInitAlias());
		$scenarioTable = $sqlHelper->quote(ScenarioTable::getTableName());

		// Semi-join keeps b_tasks_scenario index-only and avoids materializing the task scope,
		// unlike an inner join that would need DISTINCT to survive duplicated scenario rows.
		$result = $query
			->whereExists(new SqlExpression(
				"SELECT 1 FROM {$scenarioTable}"
				. " WHERE {$scenarioTable}.?# = {$taskAlias}.?#"
				. " AND {$scenarioTable}.?# = ?s",
				'TASK_ID',
				'ID',
				'SCENARIO',
				Scenario::Voice->value,
			))
			->exec()
		;

		$counts = [];
		while ($row = $result->fetch())
		{
			$count = (int)$row['TASKS_COUNT'];
			if ($count !== 0)
			{
				$counts[(int)$row['CREATED_BY']] = $count;
			}
		}

		return $counts;
	}
}
