<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Infrastructure\Dto\FullReport\Create;
use Bitrix\Timeman\V2\Infrastructure\Dto\FullReport\Update;
use Bitrix\Timeman\V2\Public\Command\FullReport\ApproveCommand;
use Bitrix\Timeman\V2\Public\Dto;
use Bitrix\Timeman\V2\Public\Command\FullReport\RejectCommand;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;

class FullReport extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Create::class,
				'report',
				static fn (string $className, array $report): Create => Create::mapFromArray($report),
			),
			new ExactParameter(
				Update::class,
				'report',
				static fn (string $className, array $report): Update => Update::mapFromArray($report),
			),
		];
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.getUserReports
	 */
	public function getUserReportsAction(
		#[PositiveNumber]
		int $userId,
		FullReportProvider $provider,
		?int $dateFrom = null,
		?int $dateTo = null,
		?PageNavigation $pageNavigation = null,
	): ?Dto\FullReport\FullReportCollection
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getReports(
			new ListParams(
				pager: Pager::buildFromPageNavigation($pageNavigation),
				filter: new Filter(
					userId: $userId,
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			),
		);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.add
	 */
	public function addAction(
		Create $report,
		FullReportProvider $provider,
	): ?Dto\FullReport\FullReport
	{
		$userId = $report->userId;
		if ($userId <= 0)
		{
			$this->addError(new Error('User ID is required.'));

			return null;
		}

		$accessManager = $this->getAccessManager();
		if (!$accessManager->canUpdateWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		$result = $report->toCommand()->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$reportId = (int)($result->getData()['id'] ?? 0);
		if ($reportId <= 0)
		{
			$this->addError(new Error('Failed to retrieve created report ID.'));

			return null;
		}

		return $provider->getById($reportId);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.update
	 */
	public function updateAction(
		Update $report,
		FullReportProvider $provider,
	): ?Dto\FullReport\FullReport
	{
		$reportId = $report->reportId;
		if ($reportId <= 0)
		{
			$this->addError(new Error('Report ID is required.'));

			return null;
		}

		$currentReport = $provider->getById($reportId);
		if (!$currentReport)
		{
			$this->addError(new Error('Report not found.'));

			return null;
		}

		$accessManager = $this->getAccessManager();
		if (!$accessManager->canUpdateWorktime($currentReport->userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		$result = $report->toCommand()->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$updatedReportId = (int)($result->getData()['id'] ?? 0);
		if ($updatedReportId <= 0)
		{
			$this->addError(new Error('Failed to retrieve updated report ID.'));

			return null;
		}

		return $provider->getById($updatedReportId);
	}
}
