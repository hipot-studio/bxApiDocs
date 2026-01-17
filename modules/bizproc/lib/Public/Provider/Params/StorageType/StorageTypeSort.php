<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageType;

use Bitrix\Main\Provider\Params\SortInterface;

class StorageTypeSort implements SortInterface
{
	private array $sort;

	public function __construct(array $sort = [])
	{
		$this->sort = $sort;
	}

	public function prepareSort(): array
	{
		return array_intersect_key($this->sort, array_flip([
			'ID',
			'TITLE',
			'CREATED_BY',
			'UPDATED_BY',
			'CREATED_TIME',
			'UPDATED_TIME',
		]));
	}
}
