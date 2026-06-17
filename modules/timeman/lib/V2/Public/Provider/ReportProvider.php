<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\Report\Report;
use Bitrix\Timeman\V2\Public\Dto\Report\ReportCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;

class ReportProvider
{
	private readonly ReportRepository $repository;
	private readonly DtoMapper $dtoMapper;

	public function __construct()
	{
		$this->repository = Container::getInstance()->getReportRepository();
		$this->dtoMapper = new DtoMapper();
	}

	public function getReports(int $userId, ListParams $params): ReportCollection
	{
		$reports = $this->repository->getReports(
			userId: $userId,
			select: $params->getSelect(),
			filter: $params->getFilter(),
			sort: $params->getSort(),
			offset: $params->getOffset(),
			limit: $params->getLimit(),
		);

		return $this->dtoMapper->mapToDtoCollection(
			$reports,
			Report::class,
			ReportCollection::class,
		);
	}

	public function getById(int $reportId): ?Report
	{
		$report = $this->repository->getById($reportId);

		return $report ? $this->dtoMapper->mapToDto($report, Report::class) : null;
	}

	public function getByRecordId(int $recordId): ?Report
	{
		$report = $this->repository->getByRecordId($recordId);

		return $report ? $this->dtoMapper->mapToDto($report, Report::class) : null;
	}
}
