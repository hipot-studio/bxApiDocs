<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Report;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Public\Provider\Params\AbstractFilter;

class Filter extends AbstractFilter
{
	public function __construct(
		private readonly int | array | null $recordId = null,
		private readonly ?int $dateFrom = null,
		private readonly ?int $dateTo = null,
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

		if (isset($this->dateFrom) && is_numeric($this->dateFrom))
		{
			$result->where('TIMESTAMP_X', '>=', DateTime::createFromTimestamp($this->dateFrom));
		}

		if (isset($this->dateTo) && is_numeric($this->dateTo))
		{
			$result->where('TIMESTAMP_X', '<=', DateTime::createFromTimestamp($this->dateTo));
		}

		return $result;
	}
}
