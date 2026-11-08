<?php

namespace Bitrix\BIConnector\Internal\Services\Scope;

use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Flow\Internal\FlowTable;

/**
 * Names of scope values (task flows, workflow templates) by their ids, without the access check.
 */
class ScopeValueNameProvider
{
	/**
	 * @param int[] $ids
	 *
	 * @return array<int, string> id => name; unknown ids are absent.
	 */
	public function getNames(Parameter $code, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$rows = match ($code)
		{
			Parameter::TasksFlowsFlowId => Loader::includeModule('tasks')
				? FlowTable::query()->setSelect(['ID', 'NAME'])->whereIn('ID', $ids)->fetchAll()
				: [],
			Parameter::WorkflowTemplateId => Loader::includeModule('bizproc')
				? WorkflowTemplateTable::query()->setSelect(['ID', 'NAME'])->whereIn('ID', $ids)->fetchAll()
				: [],
			default => [],
		};

		$names = [];
		foreach ($rows as $row)
		{
			$name = trim((string)$row['NAME']);
			if ($name !== '')
			{
				$names[(int)$row['ID']] = $name;
			}
		}

		return $names;
	}
}
