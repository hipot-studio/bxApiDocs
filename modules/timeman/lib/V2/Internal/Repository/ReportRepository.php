<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Model\Worktime\Report\WorktimeReportTable;
use Bitrix\Timeman\V2\Internal\Entity\Report\RecordReportType;
use Bitrix\Timeman\V2\Internal\Entity\Report\Report;
use Bitrix\Timeman\V2\Internal\Entity\Report\ReportCollection;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\ReportMapper;

class ReportRepository
{
	public function __construct(
		private readonly ReportMapper $mapper,
	)
	{
	}

	public function getReports(
		int $userId,
		?array $select = null,
		?ConditionTree $filter = null,
		?array $sort = null,
		int $offset = 0,
		int $limit = 50,
		string $reportType = RecordReportType::REPORT,
	): ReportCollection
	{
		$query = WorktimeReportTable::query()->setSelect(array_merge(['*'], $select ?: []));

		$query->where('USER_ID', '=', $userId);

		$query->where('REPORT_TYPE', '=', $reportType);

		if ($filter)
		{
			$query->where($filter);
		}

		$query->setOrder(array_merge(['ID' => 'DESC'], $sort ?: []));

		$query->setOffset($offset)->setLimit($limit);

		$reports = $query->exec()->fetchAll();

		return $this->mapper->mapToCollection($reports);
	}

	public function getById(int $reportId): ?Report
	{
		$report = WorktimeReportTable::query()
			->addSelect('*')
			->where('ID', $reportId)
			->exec()
			->fetchObject();

		if (!$report)
		{
			return null;
		}

		return $this->mapper->mapFromOrm($report);
	}

	public function getByRecordId(
		int $recordId,
		array $reportTypes = [RecordReportType::REPORT],
	): ?Report
	{
		$report = WorktimeReportTable::query()
			->addSelect('*')
			->where('ENTRY_ID', $recordId)
			->whereIn('REPORT_TYPE', $reportTypes)
			->addOrder('ID', 'DESC')
			->exec()
			->fetchObject();

		if (!$report)
		{
			return null;
		}

		return $this->mapper->mapFromOrm($report);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByRecordIdAndType(int $recordId, string $reportType): ?Report
	{
		$report = WorktimeReportTable::query()
			->addSelect('*')
			->where('ENTRY_ID', $recordId)
			->where('REPORT_TYPE', $reportType)
			->exec()
			->fetchObject()
		;

		if (!$report)
		{
			return null;
		}

		return $this->mapper->mapFromOrm($report);
	}

	public function getReportsByRecordIds(
		int $userId,
		array $entryIds,
		array $reportTypes = [RecordReportType::REPORT],
		bool $activeOnly = true,
	): ReportCollection
	{
		if (empty($entryIds))
		{
			return new ReportCollection();
		}

		$query = WorktimeReportTable::query()
			->addSelect('*')
			->where('USER_ID', $userId)
			->whereIn('ENTRY_ID', $entryIds)
			->whereIn('REPORT_TYPE', $reportTypes)
			->addOrder('ENTRY_ID', 'ASC')
			->addOrder('ID', 'DESC');

		if ($activeOnly)
		{
			$query->where('ACTIVE', 'Y');
		}

		$rows = $query->exec()->fetchAll();

		$reports = [];
		foreach ($rows as $row)
		{
			$entryId = (int)($row['ENTRY_ID'] ?? 0);
			if (!$entryId || isset($reports[$entryId]))
			{
				continue;
			}

			$reports[$entryId] = $row;
		}

		return $this->mapper->mapToCollection(array_values($reports));
	}

	public function upsertRecordReport(
		int $recordId,
		int $userId,
		string $reportText,
		string $reportType = RecordReportType::REPORT,
	): Result
	{
		$result = new Result();

		$fields = [
			'ENTRY_ID' => $recordId,
			'USER_ID' => $userId,
			'REPORT_TYPE' => $reportType,
			'REPORT' => $reportText,
			'ACTIVE' => 'Y',
			'TIMESTAMP_X' => new DateTime(),
		];

		$existingReport = WorktimeReportTable::query()
			->addSelect('ID')
			->where('ENTRY_ID', $recordId)
			->where('REPORT_TYPE', $reportType)
			->exec()
			->fetchObject();

		try
		{
			$operationResult = $existingReport
				? WorktimeReportTable::update((int)$existingReport->getId(), $fields)
				: WorktimeReportTable::add($fields);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage(), (string)$e->getCode()));

			return $result;
		}

		if (!$operationResult->isSuccess())
		{
			$result->addErrors($operationResult->getErrors());

			return $result;
		}

		return $result;
	}

	/**
	 * Returns report texts by entry ids for given report type.
	 *
	 * @param int $userId
	 * @param int[] $entryIds
	 * @param string[] $reportTypes
	 * @param bool $activeOnly
	 * @return array<int, string> entryId => reportText
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getReportTexts(
		int $userId,
		array $entryIds,
		array $reportTypes = [RecordReportType::REPORT],
		bool $activeOnly = true,
	): array
	{
		$entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
		if ($userId <= 0 || empty($entryIds))
		{
			return [];
		}

		$query = WorktimeReportTable::query()
			->addSelect('ID')
			->addSelect('ENTRY_ID')
			->addSelect('REPORT')
			->where('USER_ID', $userId)
			->whereIn('ENTRY_ID', $entryIds)
			->whereIn('REPORT_TYPE', $reportTypes)
			->addOrder('ENTRY_ID', 'ASC')
			->addOrder('ID', 'DESC');

		if ($activeOnly)
		{
			$query->where('ACTIVE', 'Y');
		}

		$rows = $query->exec()->fetchAll();

		$reportTexts = [];
		foreach ($rows as $row)
		{
			$entryId = (int)($row['ENTRY_ID'] ?? 0);
			if (!$entryId || isset($reportTexts[$entryId]))
			{
				continue;
			}

			$reportTexts[$entryId] = (string)($row['REPORT'] ?? '');
		}

		return $reportTexts;
	}
}
