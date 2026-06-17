<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Public\Dto;
use Bitrix\Timeman\V2\Public\Command\Worktime\ContinueCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\PauseCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\StartCommand;
use Bitrix\Timeman\V2\Public\Command\Worktime\StopCommand;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

class Record extends BaseController
{
	/**
	 * @ajaxAction timeman.V2.Record.getUserRecords
	 */
	public function getUserRecordsAction(
		#[PositiveNumber]
		int $userId,
		RecordProvider $provider,
		?int $dateFrom = null,
		?int $dateTo = null,
		?PageNavigation $pageNavigation = null,
	): ?Dto\Record\RecordCollection
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canReadWorktime($userId))
		{
			$this->addError(new Error('Access denied.'));

			return null;
		}

		return $provider->getRecords(
			new ListParams(
				pager: Pager::buildFromPageNavigation($pageNavigation),
				filter: new Filter(
					userId: $userId,
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			)
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
	public function startAction(): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new StartCommand(
			userId: $this->userId,
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
	public function pauseAction(): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new PauseCommand(
			userId: $this->userId,
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
	public function stopAction(
		?string $reason = null,
		#[PositiveNumber]
		?int $stopTimestamp = null,
	): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new StopCommand(
			userId: $this->userId,
			reason: $reason,
			stopTimestamp: $stopTimestamp,
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
	public function continueAction(): bool
	{
		$accessManager = $this->getAccessManager();

		if (!$accessManager->canManageWorktime())
		{
			$this->addError(new Error('Access denied.'));

			return false;
		}

		$command = new ContinueCommand(
			userId: $this->userId,
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
