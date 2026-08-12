<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Report\ListRequest;
use Bitrix\Timeman\V2\Public\Command\Report\UpsertCommand;
use Bitrix\Timeman\V2\Public\Dto;
use Bitrix\Timeman\V2\Public\Dto\Report\ReportCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Select;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Sort;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\ReportProvider;

class Report extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			...parent::getAutoWiredParameters(),
			new ValidationParameter(
				ListRequest::class,
				fn(): ListRequest => ListRequest::createFromRequest($this->getRequest()),
			),
		];
	}

	/**
	 * @ajaxAction timeman.V2.Report.getUserReports
	 */
	public function getUserReportsAction(
		ListRequest $request,
		ReportProvider $provider,
	): ?ReportCollection
	{
		$accessManager = $this->getAccessManager();
		$userId = $request->getUserId();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getReports(
			$userId,
			new ListParams(
				pager: $request->pagination->prepare(),
				filter: $request->filter->prepare(),
				sort: $request->order->prepare(Sort::class),
				select: $request->select->prepare(Select::class),
			),
		);
	}

	/**
	 * @ajaxAction timeman.V2.Report.getCurrentReport
	 */
	public function getCurrentReportAction(
		#[PositiveNumber]
		int $recordId,
		ReportProvider $provider,
		RecordProvider $recordProvider,
		bool $withAi = false,
	): ?Dto\Report\Report
	{
		$accessManager = $this->getAccessManager();

		$record = $recordProvider->getById(
			recordId: $recordId,
			includeShift: false,
			includeSchedule: false,
		);
		if (!$record)
		{
			$this->addError(new Error('Record not found.'));

			return null;
		}

		if (!$accessManager->canReadWorktime($record->userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getByRecordId($recordId, $withAi);
	}

	/**
	 * @ajaxAction timeman.V2.Report.getDayPlan
	 */
	public function getDayPlanAction(
		#[PositiveNumber]
		int $recordId,
		ReportProvider $provider,
		RecordProvider $recordProvider,
	): ?Dto\Report\Report
	{
		$accessManager = $this->getAccessManager();

		$record = $recordProvider->getById(
			recordId: $recordId,
			includeShift: false,
			includeSchedule: false,
		);
		if (!$record)
		{
			$this->addError(new Error('Record not found.'));

			return null;
		}

		if (!$accessManager->canReadWorktime($record->userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getDayPlanByRecordId($recordId);
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
		$record = $recordProvider->getById($recordId);
		if (!$record)
		{
			$this->addError(new Error('Record not found.'));

			return false;
		}

		if ($record->userId !== $this->userId)
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
