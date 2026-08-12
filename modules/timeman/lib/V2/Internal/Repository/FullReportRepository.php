<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Timeman\Model\Report\FullReportTable;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportCollection;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\FullReportMapper;

class FullReportRepository
{
	public function __construct(
		private readonly FullReportMapper $reportMapper,
	)
	{
	}

	public function add(FullReport $report): Result
	{
		$result = new Result();

		$fields = $this->reportMapper->mapFromEntity($report);
		$addResult = FullReportTable::add($fields);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$result->setData(['id' => (int)$addResult->getId()]);

		return $result;
	}

	public function update(FullReport $report): Result
	{
		$result = new Result();

		if ($report->id <= 0)
		{
			return $result->addError(new Error('Report ID is required'));
		}

		$fields = $this->reportMapper->mapFromEntity($report, true);
		unset($fields['ID']);

		$updateResult = FullReportTable::update($report->id, $fields);
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());

			return $result;
		}

		$result->setData(['id' => $report->id]);

		return $result;
	}

	public function getPeriodContextById(int $reportId): ?array
	{
		$row = FullReportTable::query()
			->addSelect('ID')
			->addSelect('USER_ID')
			->addSelect('DATE_FROM')
			->addSelect('DATE_TO')
			->where('ID', $reportId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return [
			'userId' => (int)($row['USER_ID'] ?? 0),
			'dateFrom' => ($row['DATE_FROM'] instanceof DateTime) ? $row['DATE_FROM'] : null,
			'dateTo' => ($row['DATE_TO'] instanceof DateTime) ? $row['DATE_TO'] : null,
		];
	}

	public function findOverlap(
		int $userId,
		int $excludeReportId,
		DateTime $from,
		DateTime $to,
	): ?array
	{
		$row = FullReportTable::query()
			->addSelect('ID')
			->addSelect('DATE_FROM')
			->addSelect('DATE_TO')
			->where('USER_ID', $userId)
			->whereNot('ID', $excludeReportId)
			->where('DATE_FROM', '<=', $to)
			->where('DATE_TO', '>=', $from)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'dateFrom' => ($row['DATE_FROM'] instanceof DateTime) ? $row['DATE_FROM'] : null,
			'dateTo' => ($row['DATE_TO'] instanceof DateTime) ? $row['DATE_TO'] : null,
		];
	}

	public function findContinuousOverlappingDateTo(int $userId, DateTime $from, DateTime $to): ?DateTime
	{
		$rows = FullReportTable::query()
			->addSelect('DATE_FROM')
			->addSelect('DATE_TO')
			->where('USER_ID', $userId)
			->where('DATE_TO', '>=', $from)
			->addOrder('DATE_FROM', 'ASC')
			->addOrder('DATE_TO', 'DESC')
			->exec()
			->fetchAll()
		;

		$fromTimestamp = $from->getTimestamp();
		$currentEndTimestamp = $to->getTimestamp();
		$maxDateTo = null;

		foreach ($rows as $row)
		{
			$dateFrom = $row['DATE_FROM'] ?? null;
			$dateTo = $row['DATE_TO'] ?? null;
			if (!$dateFrom instanceof DateTime || !$dateTo instanceof DateTime)
			{
				continue;
			}

			if ($dateTo->getTimestamp() < $fromTimestamp)
			{
				continue;
			}

			if ($dateFrom->getTimestamp() > $currentEndTimestamp)
			{
				break;
			}

			if ($maxDateTo === null || $dateTo->getTimestamp() > $maxDateTo->getTimestamp())
			{
				$maxDateTo = $dateTo;
			}

			if ($dateTo->getTimestamp() > $currentEndTimestamp)
			{
				$currentEndTimestamp = $dateTo->getTimestamp();
			}
		}

		return $maxDateTo;
	}

	public function getList(
		?array $select = null,
		?ConditionTree $filter = null,
		?array $sort = null,
		int $offset = 0,
		int $limit = 50,
	): FullReportCollection
	{
		$query = $this->buildListQuery($select, $filter, $sort);
		$query->setOffset($offset)->setLimit($limit);

		$rows = $query->exec()->fetchAll();

		return $this->reportMapper->mapToCollection($rows);
	}

	public function getAll(
		?array $select = null,
		?ConditionTree $filter = null,
		?array $sort = null,
	): FullReportCollection
	{
		$rows = $this->buildListQuery($select, $filter, $sort)
			->exec()
			->fetchAll()
		;

		return $this->reportMapper->mapToCollection($rows);
	}

	/**
	 * @return array<int, int>
	 */
	public function getDistinctUserIds(
		?ConditionTree $filter = null,
		int $offset = 0,
		int $limit = 50,
		?int $priorityUserId = null,
	): array
	{
		$query = FullReportTable::query()
			->registerRuntimeField(
				new Reference('USER', UserTable::class, Join::on('this.USER_ID', 'ref.ID')),
			)
			->addSelect('USER_ID')
			->addSelect('USER.LAST_NAME', 'USER_LAST_NAME')
			->addSelect('USER.NAME', 'USER_NAME')
			->setDistinct(true)
			->setOffset($offset)
			->setLimit($limit)
		;

		$order = [];
		if ($priorityUserId !== null && $priorityUserId > 0)
		{
			$query->registerRuntimeField(
				new ExpressionField(
					'IS_PRIORITY_USER',
					'(%s = ' . $priorityUserId . ')',
					['USER_ID'],
				),
			);
			$query->addSelect('IS_PRIORITY_USER');
			$order['IS_PRIORITY_USER'] = 'DESC';
		}
		$order['USER.NAME'] = 'ASC';
		$order['USER.LAST_NAME'] = 'ASC';
		$order['USER_ID'] = 'ASC';
		$query->setOrder($order);

		if ($filter)
		{
			$query->where($filter);
		}

		$userIds = array_column($query->exec()->fetchAll(), 'USER_ID');
		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn (int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}

	public function getById(int $reportId): ?FullReport
	{
		$row = FullReportTable::query()
			->addSelect('*')
			->where('ID', $reportId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return $this->reportMapper->mapToEntity($row);
	}

	public function findLatestDraft(int $userId): ?FullReport
	{
		$row = FullReportTable::query()
			->addSelect('*')
			->where('USER_ID', $userId)
			->where('ACTIVE', false)
			->setOrder(['DATE_FROM' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return $this->reportMapper->mapToEntity($row);
	}

	private function buildListQuery(
		?array $select = null,
		?ConditionTree $filter = null,
		?array $sort = null,
	): \Bitrix\Main\ORM\Query\Query
	{
		$query = FullReportTable::query()->setSelect(array_merge(['*'], $select ?: []));

		if ($filter)
		{
			$query->where($filter);
		}

		$query->setOrder(array_merge(['ID' => 'DESC'], $sort ?: []));

		return $query;
	}
}
