<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\FullReport;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Public\Provider\Params\AbstractFilter;

class Filter extends AbstractFilter
{
	public function __construct(
		private readonly ?bool $activeOnly = null,
		private readonly ?int $userId = null,
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

		if ($this->activeOnly === true)
		{
			$result->where('ACTIVE', '=', 'Y');
		}

		if ($this->userId)
		{
			$result->where('USER_ID', '=', (int)$this->userId);
		}

		if (isset($this->dateFrom) && is_numeric($this->dateFrom))
		{
			$result->where('DATE_TO', '>=', DateTime::createFromTimestamp($this->dateFrom));
		}

		if (isset($this->dateTo) && is_numeric($this->dateTo))
		{
			$result->where('DATE_FROM', '<=', DateTime::createFromTimestamp($this->dateTo));
		}

		return $result;
	}
}
