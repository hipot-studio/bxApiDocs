<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugTrace;

use Bitrix\Main\Provider\Params\SortInterface;

class DebugTraceSort implements SortInterface
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
			'DEBUG_SESSION_ID',
			'TYPE',
			'TIMESTAMP',
		]));
	}
}

