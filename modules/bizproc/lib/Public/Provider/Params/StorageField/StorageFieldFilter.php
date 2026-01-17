<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageField;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

class StorageFieldFilter implements FilterInterface
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

		if (isset($this->filter['STORAGE_ID']))
		{
			$result->where('STORAGE_ID', '=', (int)$this->filter['STORAGE_ID']);
		}

		if (isset($this->filter['CODE']))
		{
			if (is_array($this->filter['CODE']))
			{
				$result->whereIn('CODE', $this->filter['CODE']);
			}
			else
			{
				$result->where('CODE', '=', (string)$this->filter['CODE']);
			}
		}

		if (isset($this->filter['NAME']))
		{
			$name = (string)$this->filter['NAME'];
			$result->whereLike('NAME', "%{$name}%");
		}

		if (isset($this->filter['STORAGE_ID']))
		{
			$name = $this->filter['STORAGE_ID'];
			$result->where('STORAGE_ID', $name);
		}

		return $result;
	}
}
