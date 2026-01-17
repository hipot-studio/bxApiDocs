<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageItem;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;

class StorageItemFilter implements FilterInterface
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
			$result->where('CODE', '=', (string)$this->filter['CODE']);
		}

		if (isset($this->filter['DOCUMENT_ID']))
		{
			$result->where('MODULE_ID', '=', (string)$this->filter['DOCUMENT_ID'][0]);
			$result->where('ENTITY', '=', (string)$this->filter['DOCUMENT_ID'][0]);
			$result->where('DOCUMENT_TYPE', '=', (string)$this->filter['DOCUMENT_ID'][0]);
		}

		if (isset($this->filter['WORKFLOW_ID']))
		{
			$result->where('WORKFLOW_ID', '=', (string)$this->filter['WORKFLOW_ID']);
		}

		if (isset($this->filter['TEMPLATE_ID']))
		{
			$result->where('TEMPLATE_ID', '=', (int)$this->filter['TEMPLATE_ID']);
		}

		if (isset($this->filter['TITLE']))
		{
			$title = (string)$this->filter['TITLE'];
			$result->whereLike('TITLE', "%{$title}%");
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
