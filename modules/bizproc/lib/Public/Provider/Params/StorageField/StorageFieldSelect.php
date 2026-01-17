<?php

namespace Bitrix\Bizproc\Public\Provider\Params\StorageField;

use Bitrix\Main\Provider\Params\SelectInterface;

class StorageFieldSelect implements SelectInterface
{
	private const DEFAULT_FIELDS = [
		'ID',
		'STORAGE_ID',
		'CODE',
		'SORT',
		'NAME',
		'DESCRIPTION',
		'TYPE',
		'MULTIPLE',
		'MANDATORY',
		'SETTINGS',
	];
	private array $select;

	public function __construct(array $select = [])
	{
		$this->select = $select;
	}

	public function prepareSelect(): array
	{
		return array_intersect($this->select, self::DEFAULT_FIELDS);
	}
}
