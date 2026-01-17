<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageItem;

use Bitrix\Main\Provider\Params\SelectInterface;

class StorageItemSelect implements SelectInterface
{
	private array $select;

	public function __construct(array $select = [])
	{
		$this->select = $select;
	}

	public function prepareSelect(): array
	{
		$result = [];

		if (in_array('ID', $this->select, true))
		{
			$result[] = 'ID';
		}

		if (in_array('CODE', $this->select, true))
		{
			$result[] = 'CODE';
		}

		if (in_array('DOCUMENT_ID', $this->select, true))
		{
			$result[] = 'DOCUMENT_ID';
		}

		if (in_array('WORKFLOW_ID', $this->select, true))
		{
			$result[] = 'WORKFLOW_ID';
		}

		if (in_array('TEMPLATE_ID', $this->select, true))
		{
			$result[] = 'TEMPLATE_ID';
		}

		if (in_array('TITLE', $this->select, true))
		{
			$result[] = 'TITLE';
		}

		if (in_array('CREATED_BY', $this->select, true))
		{
			$result[] = 'CREATED_BY';
		}

		if (in_array('UPDATED_BY', $this->select, true))
		{
			$result[] = 'UPDATED_BY';
		}

		if (in_array('CREATED_TIME', $this->select, true))
		{
			$result[] = 'CREATED_TIME';
		}

		if (in_array('UPDATED_TIME', $this->select, true))
		{
			$result[] = 'UPDATED_TIME';
		}

		return $result;
	}
}
