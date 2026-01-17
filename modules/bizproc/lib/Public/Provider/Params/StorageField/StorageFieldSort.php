<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageField;

use Bitrix\Main\Provider\Params\SortInterface;

class StorageFieldSort implements SortInterface
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
			'CODE',
			'SORT',
			'NAME',
			'TYPE',
			'MULTIPLE',
			'MANDATORY',
		]));
	}
}
