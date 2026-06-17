<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\DebugSession;

use Bitrix\Main\Provider\Params\SelectInterface;

class DebugSessionSelect implements SelectInterface
{
	private const DEFAULT_FIELDS = [
		'ID',
		'USER_ID',
		'DEBUG_ID',
		'MODULE_ID',
		'ENTITY',
		'DOCUMENT_ID',
		'WORKFLOW_ID',
		'TEMPLATE_ID',
		'START_TIME',
		'END_TIME',
		'METADATA',
		'LOGS',
		'METRICS',
		'CREATED_AT',
		'UPDATED_AT',
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

