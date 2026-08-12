<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Report;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Timeman\V2\Public\Provider\Params\AbstractFilter;

class Filter extends AbstractFilter
{
	public function __construct(
		private readonly int | array | null $recordId = null,
		private readonly ?bool $withAi = null,
	)
	{
	}

	protected static function fieldsEnumClass(): string
	{
		return FieldsEnum::class;
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->recordId))
		{
			if (is_array($this->recordId))
			{
				$result->whereIn('ENTRY_ID', array_map('intval', $this->recordId));
			}
			else
			{
				$result->where('ENTRY_ID', '=', (int)$this->recordId);
			}
		}

		return $result;
	}

	public function isWithAi(): bool
	{
		return $this->withAi === true;
	}
}
