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

		if ($this->activeOnly === true)
		{
			$result->where('ACTIVE', '=', 'Y');
		}

		if (count($userIds) === 1)
		{
			$result->where('USER_ID', '=', $userIds[0]);
		}
		elseif (!empty($userIds))
		{
			$result->whereIn('USER_ID', $userIds);
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

	public function withUserIds(array $userIds): self
	{
		return new self(
			activeOnly: $this->activeOnly,
			userId: $this->userId,
			userIds: $userIds,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
		);
	}

	public function withActiveOnly(bool $activeOnly): self
	{
		return new self(
			activeOnly: $activeOnly,
			userId: $this->userId,
			userIds: $this->userIds,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
		);
	}

	/**
	 * @return array<int, int>
	 */
	public function getUserIds(): array
	{
		$userIds = $this->userIds ?? [];
		if ($this->userId !== null)
		{
			$userIds[] = $this->userId;
		}

		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn (int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}
}
