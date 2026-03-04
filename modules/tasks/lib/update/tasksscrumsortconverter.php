<?php

namespace Bitrix\Tasks\Update;

use Bitrix\Main\Config\Option;

class TasksScrumSortConverter implements AgentInterface
{
	use AgentTrait;

	private static $processing = false;
	private $limit;

	public static function execute(): string
	{
		if (self::$processing)
		{
			return self::getAgentName();
		}

		self::$processing = true;

		$agent = new self();
		$res = $agent->run();

		self::$processing = false;

		return $res;
	}

	private function __construct()
	{
		$this->limit = $this->getLimit();
	}

	private function run(): string
	{
		global $DB;

		$ids = $this->getRowIds();
		if (empty($ids))
		{
			return '';
		}

		$idsList = implode(',', array_map('intval', $ids));

		$DB->Query("
			UPDATE b_tasks_scrum_item 
			SET SORT_FLOAT = CASE 
				WHEN SORT = 0 THEN 512.0
				ELSE CAST(SORT AS DECIMAL(10,2)) * 1024.0 
			END
			WHERE ID IN ({$idsList})
			AND SORT IS NOT NULL 
			AND SORT_FLOAT IS NULL
		");

		return self::getAgentName();
	}

	private function getRowIds(): array
	{
		global $DB;

		$ids = [];

		$queryObject = $DB->Query("
			SELECT ID
			FROM b_tasks_scrum_item 
			WHERE SORT IS NOT NULL 
			AND SORT_FLOAT IS NULL
			ORDER BY ID
			LIMIT {$this->limit}
		");
		while ($data = $queryObject->Fetch())
		{
			$ids[] = (int)$data['ID'];
		}

		return $ids;
	}

	private function getLimit(): int
	{
		return (int)Option::get('tasks', 'TasksScrumSortConverterAgentLimit', 1000);
	}
}
