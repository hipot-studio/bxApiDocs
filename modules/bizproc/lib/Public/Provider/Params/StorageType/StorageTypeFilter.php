<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageType;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

class StorageTypeFilter implements FilterInterface
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

		if (isset($this->filter['CODE']))
		{
			$code = (string)$this->filter['CODE'];
			$result->whereLike('CODE', "%{$code}%");
		}

		if (isset($this->filter['TITLE']))
		{
			$title = (string)$this->filter['TITLE'];
			$result->whereLike('TITLE', "%{$title}%");
		}

		if (isset($this->filter['DESCRIPTION']))
		{
			$description = (string)$this->filter['DESCRIPTION'];
			$result->whereLike('DESCRIPTION', "%{$description}%");
		}

		if (isset($this->filter['FIND']))
		{
			$find = trim((string)$this->filter['FIND']);
			if ($find !== '')
			{
				$pattern = '%' . $find . '%';
				$searchTree = (new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->whereLike('TITLE', $pattern)
					->whereLike('CODE', $pattern)
					->whereLike('DESCRIPTION', $pattern)
				;
				$result->where($searchTree);
			}
		}

		if (isset($this->filter['CREATED_BY']))
		{
			if (is_array($this->filter['CREATED_BY']))
			{
				$result->whereIn('CREATED_BY', array_map('intval', $this->filter['CREATED_BY']));
			}
			else
			{
				$result->where('CREATED_BY', '=', (int)$this->filter['CREATED_BY']);
			}
		}

		if (isset($this->filter['UPDATED_BY']))
		{
			if (is_array($this->filter['UPDATED_BY']))
			{
				$result->whereIn('UPDATED_BY', array_map('intval', $this->filter['UPDATED_BY']));
			}
			else
			{
				$result->where('UPDATED_BY', '=', (int)$this->filter['UPDATED_BY']);
			}
		}

		if (
			isset($this->filter['CREATED_WITHIN']['FROM'])
			&& isset($this->filter['CREATED_WITHIN']['TO'])
		)
		{
			$result
				->where('CREATED_TIME', '>=', $this->filter['CREATED_WITHIN']['FROM'])
				->where('CREATED_TIME', '<', $this->filter['CREATED_WITHIN']['TO'])
			;
		}

		if (
			isset($this->filter['UPDATED_WITHIN']['FROM'])
			&& isset($this->filter['UPDATED_WITHIN']['TO'])
		)
		{
			$result
				->where('UPDATED_TIME', '>=', $this->filter['UPDATED_WITHIN']['FROM'])
				->where('UPDATED_TIME', '<', $this->filter['UPDATED_WITHIN']['TO'])
			;
		}

		return $result;
	}
}
