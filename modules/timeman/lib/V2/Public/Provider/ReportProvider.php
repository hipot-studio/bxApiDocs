<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Entity;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;
use Bitrix\Timeman\V2\Internal\Service\ReportTextNormalizerService;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;
use Bitrix\Timeman\V2\Public\Dto\Report\Report;
use Bitrix\Timeman\V2\Public\Dto\Report\ReportCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter;

class ReportProvider
{
	private readonly ReportRepository $repository;
	private readonly ReportTextNormalizerService $reportTextNormalizerService;
	private readonly DtoMapper $dtoMapper;

	public function __construct()
	{
		$container = Container::getInstance();
		$this->repository = $container->getReportRepository();
		$this->reportTextNormalizerService = $container->getReportTextNormalizerService();
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

		if (!$this->shouldLoadAiReports($params) || $reports->isEmpty())
		{
			$items = [];
			foreach ($reports as $report)
			{
				$items[] = $this->buildReportDto($report);
			}

			return new ReportCollection(...$items);
		}

		$extendedReports = $this->repository->getReportsByRecordIds(
			userId: $userId,
			entryIds: $this->extractRecordIds($reports),
			reportTypes: RecordReportType::getExtendedValues(),
			activeOnly: false,
		);

		return $this->extendReportsWithExtendedReports($reports, $extendedReports);
	}

	public function getById(int $reportId): ?Report
	{
		$report = $this->repository->getById($reportId);

		return $report ? $this->buildReportDto($report) : null;
	}

	public function getByRecordId(int $recordId, bool $withAi = false): ?Report
	{
		$report = $this->repository->getByRecordId($recordId);
		if (!$report)
		{
			return null;
		}

		$extendedReport = $withAi
			? $this->repository->getByRecordId($recordId, RecordReportType::getExtendedValues())
			: null;

		return $this->extendReportWithExtendedReport($report, $extendedReport);
	}

	public function getDayPlanByRecordId(int $recordId): ?Report
	{
		$report = $this->repository->getByRecordId($recordId, RecordReportType::getPlanValues());
		if (!$report)
		{
			return null;
		}

		return $this->buildReportDto($report);
	}

	private function extendReportWithExtendedReport(
		Entity\Report\Report $report,
		?Entity\Report\Report $extendedReport = null,
	): Report
	{
		return $this->extendReportWithExtendedReportText(
			$report,
			$extendedReport?->report,
			$extendedReport?->type,
		);
	}

	private function extendReportsWithExtendedReports(
		Entity\Report\ReportCollection $reports,
		Entity\Report\ReportCollection $extendedReports,
	): ReportCollection
	{
		$extendedReportsByRecordId = [];
		foreach ($extendedReports as $extendedReport)
		{
			$extendedReportsByRecordId[$extendedReport->recordId] = $extendedReport;
		}

		$items = [];
		foreach ($reports as $report)
		{
			$extendedReport = $extendedReportsByRecordId[$report->recordId] ?? null;
			$items[] = $this->extendReportWithExtendedReportText(
				$report,
				$extendedReport?->report,
				$extendedReport?->type,
			);
		}

		return new ReportCollection(...$items);
	}

	private function extendReportWithExtendedReportText(
		Entity\Report\Report $report,
		?string $extendedReportText = null,
		?string $extendedReportType = null,
	): Report
	{
		$payload = array_merge(
			$report->toArray(),
			[
				'type' => $extendedReportType ?? $report->type,
				'reportExtended' => $extendedReportText,
			],
		);
		$payload['report'] = $this->reportTextNormalizerService->normalize(
			is_string($payload['report'] ?? null) ? $payload['report'] : null,
		);

		return $this->dtoMapper->mapToDto($payload, Report::class);
	}

	private function buildReportDto(Entity\Report\Report $report): Report
	{
		$payload = $report->toArray();
		$payload['report'] = $this->reportTextNormalizerService->normalize(
			is_string($payload['report'] ?? null) ? $payload['report'] : null,
		);

		return $this->dtoMapper->mapToDto($payload, Report::class);
	}

	private function extractRecordIds(Entity\Report\ReportCollection $reports): array
	{
		$recordIds = [];
		foreach ($reports as $report)
		{
			if ($report->recordId <= 0)
			{
				continue;
			}

			$recordIds[$report->recordId] = $report->recordId;
		}

		return array_values($recordIds);
	}

	private function shouldLoadAiReports(ListParams $params): bool
	{
		$filter = $params->filter;

		return $filter instanceof Filter && $filter->isWithAi();
	}
}
