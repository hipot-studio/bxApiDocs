<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageType;

use Bitrix\Main\Provider\Params\SelectInterface;

class StorageTypeSelect implements SelectInterface
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

		if (in_array('TITLE', $this->select, true))
		{
			$result[] = 'TITLE';
		}

		if (in_array('DESCRIPTION', $this->select, true))
		{
			$result[] = 'DESCRIPTION';
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
