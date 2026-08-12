<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\FullReport\ListRequest;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\FullReport\SubordinateListRequest;
use Bitrix\Timeman\V2\Infrastructure\Dto\FullReport\Create;
use Bitrix\Timeman\V2\Infrastructure\Dto\FullReport\Update;
use Bitrix\Timeman\V2\Public\Command\FullReport\AddCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\ApproveCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\PostponeCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\RejectCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\SendCommand;
use Bitrix\Timeman\V2\Public\Command\FullReport\UpdateCommand;
use Bitrix\Timeman\V2\Public\Dto;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReportCollection;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReportsPage;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Select;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Sort;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;

class FullReport extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			...parent::getAutoWiredParameters(),
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
			new ValidationParameter(
				ListRequest::class,
				fn(): ListRequest => ListRequest::createFromRequest($this->getRequest()),
			),
			new ValidationParameter(
				SubordinateListRequest::class,
				fn(): SubordinateListRequest => SubordinateListRequest::createFromRequest($this->getRequest()),
			),
		];
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.getUserReport
	 */
	public function getUserReportAction(
		#[PositiveNumber]
		int $reportId,
		#[PositiveNumber]
		int $userId,
		FullReportProvider $provider,
	): ?Dto\FullReport\FullReport
	{
		$accessManager = $this->getAccessManager();

		$report = $provider->getById($reportId);
		if (!$report)
		{
			$this->addError(new Error('Report not found.'));

			return null;
		}

		if (
			$userId !== $report->userId
			|| !$accessManager->canReadWorktime($report->userId)
		)
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $report;
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.getUserReports
	 */
	public function getUserReportsAction(
		ListRequest $request,
		FullReportProvider $provider,
	): ?FullReportCollection
	{
		$accessManager = $this->getAccessManager();
		$userId = $request->getUserId();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getReports(
			new ListParams(
				pager: $request->pagination->prepare(),
				filter: $request->filter->prepare(),
				sort: $request->order->prepare(Sort::class),
				select: $request->select->prepare(Select::class),
			),
		);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.getSubordinateUserReports
	 */
	public function getSubordinateUserReportsAction(
		SubordinateListRequest $request,
		FullReportProvider $provider,
		?PageNavigation $nav = null,
	): ?UserReportsPage
	{
		$accessManager = $this->getAccessManager();
		if (!$accessManager->canReadWorktimeAll() && !$accessManager->canReadWorktimeSubordinate())
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getSubordinateUserReports(
			managerUserId: $this->userId,
			filter: $request->filter->prepare(),
			sort: $request->order->prepare(Sort::class),
			select: $request->select->prepare(Select::class),
			userOffset: $nav?->getOffset() ?? $request->getUserOffset(),
			userLimit: $nav?->getLimit() ?? $request->getUserLimit(),
		);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.hasOtherSubordinates
	 */
	public function hasOtherSubordinatesAction(
		FullReportProvider $provider,
	): ?bool
	{
		$accessManager = $this->getAccessManager();
		if (!$accessManager->canReadWorktimeAll() && !$accessManager->canReadWorktimeSubordinate())
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->hasOtherSubordinates($this->userId);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.getReportToSend
	 */
	public function getReportToSendAction(
		FullReportProvider $provider,
		bool $force = true,
		bool $withDraftFallback = false,
	): Dto\FullReport\FullReport
	{
		return $provider->getReportToSend($this->userId, $force, $withDraftFallback);
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

		if (!$this->canModifyReportForUser($userId))
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

		if (!$this->canModifyReportForUser($currentReport->userId))
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

	/**
	 * @ajaxAction timeman.V2.FullReport.send
	 */
	public function sendAction(
		#[PositiveNumber]
		int $reportId,
		FullReportProvider $provider,
	): ?Dto\FullReport\FullReport
	{
		$report = $provider->getById($reportId);
		if (!$report)
		{
			$this->addError(new Error('Report not found.'));

			return null;
		}

		if (!$this->canModifyReportForUser($report->userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		$command = new SendCommand(
			reportId: $reportId,
			senderId: (int)$this->getCurrentUser()->getId(),
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$sentReportId = (int)($result->getData()['id'] ?? 0);
		if ($sentReportId <= 0)
		{
			$this->addError(new Error('Failed to retrieve sent report ID.'));

			return null;
		}

		return $provider->getById($sentReportId);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.submit
	 */
	public function submitAction(
		FullReportProvider $provider,
		int $reportId = 0,
		string $reportText = '',
	): ?Dto\FullReport\FullReport
	{
		$senderId = (int)$this->getCurrentUser()->getId();
		if ($senderId <= 0)
		{
			$this->addError(new Error('User ID is required.'));

			return null;
		}

		if ($reportId > 0)
		{
			$report = $provider->getById($reportId);
			if (!$report)
			{
				$this->addError(new Error('Report not found.'));

				return null;
			}

			if (!$this->canModifyReportForUser($report->userId))
			{
				$this->addError(new Error('Access denied.'));

				return null;
			}

			$updateResult = (new UpdateCommand(
				reportId: $reportId,
				reportText: $reportText,
			))->run();

			if (!$updateResult->isSuccess())
			{
				$this->addErrors($updateResult->getErrors());

				return null;
			}
		}
		else
		{
			$addResult = (new AddCommand(
				userId: $senderId,
				reportText: $reportText,
			))->run();

			if (!$addResult->isSuccess())
			{
				$this->addErrors($addResult->getErrors());

				return null;
			}

			$reportId = (int)($addResult->getData()['id'] ?? 0);
			if ($reportId <= 0)
			{
				$this->addError(new Error('Failed to retrieve created report ID.'));

				return null;
			}
		}

		$reportToSend = $provider->getById($reportId);
		if (!$reportToSend)
		{
			$this->addError(new Error('Report not found.'));

			return null;
		}

		if (!$this->canModifyReportForUser($reportToSend->userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		$sendResult = (new SendCommand(
			reportId: $reportId,
			senderId: $senderId,
		))->run();

		if (!$sendResult->isSuccess())
		{
			$this->addErrors($sendResult->getErrors());

			return null;
		}

		$sentReportId = (int)($sendResult->getData()['id'] ?? 0);
		if ($sentReportId <= 0)
		{
			$this->addError(new Error('Failed to retrieve sent report ID.'));

			return null;
		}

		return $provider->getById($sentReportId);
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.approve
	 */
	public function approveAction(
		#[PositiveNumber]
		int $reportId,
		FullReportProvider $provider,
	): bool
	{
		$accessManager = $this->getAccessManager();

		$report = $provider->getById($reportId);
		if (!$report)
		{
			$this->addError(new Error('Report not found.'));

			return false;
		}

		if (!$accessManager->canUpdateWorktime($report->userId))
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new ApproveCommand(
			reportId: $reportId,
			approverId: (int)$this->getCurrentUser()->getId(),
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction timeman.V2.FullReport.reject
	 */
	public function rejectAction(
		#[PositiveNumber]
		int $reportId,
		FullReportProvider $provider,
	): bool
	{
		$accessManager = $this->getAccessManager();

		$report = $provider->getById($reportId);
		if (!$report)
		{
			$this->addError(new Error('Report not found.'));

			return false;
		}

		if (!$accessManager->canUpdateWorktime($report->userId))
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new RejectCommand(
			reportId: $reportId,
			approverId: (int)$this->getCurrentUser()->getId(),
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	public function postponeAction(int $seconds = 3600): bool
	{
		$command = new PostponeCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			seconds: $seconds,
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	private function canModifyReportForUser(int $userId): bool
	{
		return $userId === $this->userId || $this->getAccessManager()->canUpdateWorktime($userId);
	}
}
