<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugTrace;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

class DebugTraceFilter implements FilterInterface
{
	private array $filter;

	public function __construct(array $filter = [])
	{
		$this->filter = $filter;
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->filter['DEBUG_SESSION_ID']))
		{
			$result->where('DEBUG_SESSION_ID', '=', (int)$this->filter['DEBUG_SESSION_ID']);
		}

		return $result;
	}
}
