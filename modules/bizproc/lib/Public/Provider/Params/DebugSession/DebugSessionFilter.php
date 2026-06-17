<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugSession;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

class DebugSessionFilter implements FilterInterface
{
	private array $filter;

	public function __construct(array $filter = [])
	{
		$this->filter = $filter;
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->filter['ID']))
		{
			if (is_array($this->filter['ID']))
			{
				$result->whereIn('ID', array_map('intval', $this->filter['ID']));
			}
			else
			{
				$result->where('ID', '=', (int)$this->filter['ID']);
			}
		}

		if (isset($this->filter['USER_ID']))
		{
			$result->where('USER_ID', '=', (int)$this->filter['USER_ID']);
		}

		if (isset($this->filter['TEMPLATE_ID']))
		{
			$result->where('TEMPLATE_ID', '=', (int)$this->filter['TEMPLATE_ID']);
		}

		if (isset($this->filter['WORKFLOW_ID']))
		{
			$result->where('WORKFLOW_ID', '=', (string)$this->filter['WORKFLOW_ID']);
		}

		return $result;
	}
}

