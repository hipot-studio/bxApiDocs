<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use Bitrix\Timeman\V2\Infrastructure\Dto\Record\Action;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Record\ListRequest;
use Bitrix\Timeman\V2\Public\Dto;
use Bitrix\Timeman\V2\Public\Command\Worktime\ContinueCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\PauseCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\StartCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\StopCommand;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Select;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Sort;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

class Record extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			...parent::getAutoWiredParameters(),
			new ValidationParameter(
				ListRequest::class,
				fn(): ListRequest => ListRequest::createFromRequest($this->getRequest()),
			),
			new ValidationParameter(
				Action::class,
				fn(): Action => Action::createFromRequest($this->getRequest()),
			),
		];
	}

	/**
	 * @ajaxAction timeman.V2.Record.getUserRecords
	 */
	public function getUserRecordsAction(
		ListRequest $request,
		RecordProvider $provider,
	): ?Dto\Record\RecordCollection
	{
		$accessManager = $this->getAccessManager();
		$userId = $request->getUserId();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getRecords(
			new ListParams(
				pager: $request->pagination->prepare(),
				filter: $request->filter->prepare(),
				sort: $request->order->prepare(Sort::class),
				select: $request->select->prepare(Select::class),
			),
		);
	}

	/**
	 * @ajaxAction timeman.V2.Record.getCurrentRecord
	 */
	public function getCurrentRecordAction(
		#[PositiveNumber]
		int $userId,
		RecordProvider $provider,
	): ?Dto\Record\Record
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getCurrentRecord($userId);
	}

	/**
	 * @ajaxAction timeman.V2.Record.start
	 */
	public function startAction(Action $record): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new StartCommand(
			userId: $this->userId,
			device: $record->device,
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
	 * @ajaxAction timeman.V2.Record.pause
	 */
	public function pauseAction(Action $record): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new PauseCommand(
			userId: $this->userId,
			device: $record->device,
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
	 * @ajaxAction timeman.V2.Record.stop
	 */
	public function stopAction(Action $record): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new StopCommand(
			userId: $this->userId,
			reason: $record->reason,
			stopTimestamp: $record->stopTimestamp,
			device: $record->device,
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
	 * @ajaxAction timeman.V2.Record.continue
	 */
	public function continueAction(Action $record): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new ContinueCommand(
			userId: $this->userId,
			device: $record->device,
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
