<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugTrace;

use Bitrix\Main\Provider\Params\SelectInterface;

class DebugTraceSelect implements SelectInterface
{
	private const DEFAULT_FIELDS = [
		'ID',
		'DEBUG_SESSION_ID',
		'KEY',
		'TYPE',
		'MESSAGE',
		'TIMESTAMP',
		'DATA',
		'CONTEXT',
		'TIMESTAMP',
	];
	
	private array $select;

	public function __construct(array $select = [])
	{
		$this->select = $select;
	}

	public function prepareSelect(): array
	{
		if (in_array('*', $this->select, true))
		{
			return self::DEFAULT_FIELDS;
		}

		return array_intersect($this->select, self::DEFAULT_FIELDS);
	}
}

