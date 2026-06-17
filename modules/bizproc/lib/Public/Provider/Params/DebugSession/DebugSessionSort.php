<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugSession;

use Bitrix\Main\Provider\Params\SortInterface;

class DebugSessionSort implements SortInterface
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
			'USER_ID',
			'TEMPLATE_ID',
			'START_TIME',
			'END_TIME',
			'CREATED_AT',
			'UPDATED_AT',
		]));
	}
}

