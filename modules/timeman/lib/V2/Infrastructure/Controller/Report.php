<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Public\Command\Report\UpsertCommand;
use Bitrix\Timeman\V2\Public\Dto\Report\ReportCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\ReportProvider;

class Report extends BaseController
{
	/**
	 * @ajaxAction timeman.V2.Report.getUserReports
	 */
	public function getUserReportsAction(
		#[PositiveNumber]
		int $userId,
		ReportProvider $provider,
		?int $recordId = null,
		?int $dateFrom = null,
		?int $dateTo = null,
		?PageNavigation $pageNavigation = null,
	): ?ReportCollection
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getReports(
			$userId,
			new ListParams(
				pager: Pager::buildFromPageNavigation($pageNavigation),
				filter: new Filter(
					recordId: $recordId,
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			),
		);
	}

	/**
	 * @ajaxAction timeman.V2.Report.saveDailyReport
	 */
	public function saveDailyReportAction(
		#[PositiveNumber]
		int $recordId,
		string $reportText,
		RecordProvider $recordProvider,
	): bool
	{
		$accessManager = $this->getAccessManager();

		$record = $recordProvider->getById($recordId);
		if (!$record)
		{
			$this->addError(new Error('Record not found.'));

			return false;
		}

		if (!$accessManager->canUpdateWorktime($record->userId))
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new UpsertCommand(
			recordId: $recordId,
			userId: $this->userId,
			reportText: $reportText,
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}
}
