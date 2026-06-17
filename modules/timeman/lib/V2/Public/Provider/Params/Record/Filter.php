<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Record;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Timeman\V2\Public\Provider\Params\AbstractFilter;

class Filter extends AbstractFilter
{
	public function __construct(
		private readonly ?int $userId = null,
		private readonly ?array $userIds = null,
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
		$userIds = $this->getUserIds();

		if (count($userIds) === 1)
		{
			$result->where('USER_ID', $userIds[0]);
		}
		elseif (!empty($userIds))
		{
			$result->whereIn('USER_ID', $userIds);
		}

		if (is_numeric($this->dateFrom))
		{
			$result->where('RECORDED_START_TIMESTAMP', '>=', $this->dateFrom);
		}

		if (is_numeric($this->dateTo))
		{
			$result->where('RECORDED_START_TIMESTAMP', '<=', $this->dateTo);
		}

		return $result;
	}

	public function getUserId(): ?int
	{
		$userIds = $this->getUserIds();

		return count($userIds) === 1 ? $userIds[0] : null;
	}

	public function getUserIds(): array
	{
		$userIds = $this->userIds ?? [];
		if ($this->userId !== null)
		{
			$userIds[] = $this->userId;
		}

		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn(int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}

	public function getDateFrom(): ?int
	{
		return $this->dateFrom;
	}

	public function getDateTo(): ?int
	{
		return $this->dateTo;
	}
}
