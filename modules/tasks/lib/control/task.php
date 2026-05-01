<?php

namespace Bitrix\Tasks\Control;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Control\Exception\TaskStopDeleteException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\V2\Internal\Service\DeleteTaskService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\Internals\Task\Result\Exception\ResultNotFoundException;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;
use CTaskAssertException;
use Exception;

/**
 * @deprecated
 *
 * Public API:
 * @use AddTaskCommand
 * @use UpdateTaskCommand
 * @use DeleteTaskCommand
 *
 * Internal API:
 * @use AddTaskService
 * @use UpdateTaskService
 * @use DeleteTaskService
 */
class Task
{
	private $needCorrectDatePlan = false;
	private $correctDatePlanDependent = false;
	private $fromAgent = false;
	private $fromWorkFlow = false;
	private $checkFileRights = false;
	private $cloneAttachments = false;
	private $skipExchangeSync = false;
	private $byPassParams = [];
	private $needAutoclose = false;
	private $skipNotifications = false;
	private $skipRecount = false;
	private $skipComments = false;
	private $skipPush = false;
	private $skipBP = false;
	private $checkUserFields = true;

	private $eventGuid;
	private $legacyOperationResultData;

	private array $skipTimeZoneFields = [];
	private bool $useConsistency = false;

	public function __construct(private int $userId)
	{
		$this->eventGuid = sha1(uniqid('AUTOGUID', true));
	}

	public function setByPassParams(array $params): self
	{
		$this->byPassParams = $params;
		return $this;
	}

	public function setEventGuid(string $guid): self
	{
		$this->eventGuid = $guid;
		return $this;
	}

	public function withSkipExchangeSync(): self
	{
		$this->skipExchangeSync = true;
		return $this;
	}

	public function withCorrectDatePlan(): self
	{
		$this->needCorrectDatePlan = true;
		return $this;
	}

	public function fromAgent(): self
	{
		$this->fromAgent = true;
		return $this;
	}

	public function fromWorkFlow(): self
	{
		$this->fromWorkFlow = true;
		return $this;
	}

	public function withFilesRights(): self
	{
		$this->checkFileRights = true;
		return $this;
	}

	public function withCloneAttachments(): self
	{
		$this->cloneAttachments = true;
		return $this;
	}

	public function withSkipNotifications(): self
	{
		$this->skipNotifications = true;
		return $this;
	}

	public function withAutoClose(): self
	{
		$this->needAutoclose = true;
		return $this;
	}

	public function withSkipRecount(): self
	{
		$this->skipRecount = true;
		return $this;
	}

	public function withSkipComments(): self
	{
		$this->skipComments = true;
		return $this;
	}

	public function withSkipPush(): self
	{
		$this->skipPush = true;
		return $this;
	}

	public function withCorrectDatePlanDependent(): self
	{
		$this->correctDatePlanDependent = true;
		return $this;
	}

	public function skipBP(): self
	{
		$this->skipBP = true;
		return $this;
	}

	public function skipCheckUserFields(): static
	{
		$this->checkUserFields = false;
		return $this;
	}

	public function skipDeadlineTimeZone(): static
	{
		$this->skipTimeZoneFields[] = 'DEADLINE';
		return $this;
	}

	public function useConsistency(): static
	{
		$this->useConsistency = true;
		return $this;
	}

	public function getLegacyOperationResultData(): ?array
	{
		return $this->legacyOperationResultData;
	}

	/**
	 * @throws TaskAddException
	 * @throws TaskNotFoundException
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws CTaskAssertException
	 * @throws Exception
	 */
	public function add(array $fields): TaskObject
	{
		$this->reset();

		$config = new AddConfig(
			userId: $this->userId,
			fromAgent: $this->fromAgent,
			fromWorkFlow: $this->fromWorkFlow,
			checkFileRights: $this->checkFileRights,
			cloneAttachments: $this->cloneAttachments,
			byPassParameters: $this->byPassParams,
			skipBP: $this->skipBP,
			skipTimeZoneFields: $this->skipTimeZoneFields,
			needCorrectDatePlan: $this->needCorrectDatePlan,
			useConsistency: $this->useConsistency,
			checkUserFields: $this->checkUserFields,
			eventGuid: $this->eventGuid
		);

		$mapper = Container::getInstance()->getOrmTaskMapper();
		$service = Container::getInstance()->getAddTaskService();

		$entity = $mapper->mapToEntity($fields, $this->skipTimeZoneFields);

		$entity = $service->add(
			task: $entity,
			config: $config,
		);

		return $mapper->mapToObject($entity);
	}

	/**
	 * @throws TaskNotFoundException
	 * @throws TaskUpdateException
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ResultNotFoundException
	 * @throws CTaskAssertException
	 * @throws Exception
	 */
	public function update(int $taskId, array $fields): TaskObject|bool
	{
		if (empty($fields))
		{
			return false;
		}

		$this->reset();

		$config = new UpdateConfig(
			userId: $this->userId,
			needCorrectDatePlan: $this->needCorrectDatePlan,
			checkFileRights: $this->checkFileRights,
			correctDatePlanDependent: $this->correctDatePlanDependent,
			needAutoclose: $this->needAutoclose,
			skipNotifications: $this->skipNotifications,
			skipRecount: $this->skipRecount,
			byPassParameters: $this->byPassParams,
			skipComments: $this->skipComments,
			skipPush: $this->skipPush,
			skipBP: $this->skipBP,
			useConsistency: $this->useConsistency,
			eventGuid:$this->eventGuid
		);

		$mapper = Container::getInstance()->getOrmTaskMapper();
		$service = Container::getInstance()->getUpdateTaskService();

		$fields['ID'] = $taskId;

		$entity = $mapper->mapToEntity($fields, $this->skipTimeZoneFields);

		try
		{
			$entity = $service->update(
				task: $entity,
				config: $config,
			);
		}
		catch (WrongTaskIdException|TaskNotExistsException)
		{
			return false;
		}

		$this->legacyOperationResultData = $config->getRuntime()->getLegacyOperationResultData();

		return $mapper->mapToObject($entity);
	}

	/**
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws LoaderException
	 * @throws NotImplementedException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws CTaskAssertException
	 * @throws Exception
	 */
	public function delete(int $taskId): bool
	{
		$this->reset();

		$config = new DeleteConfig(
			userId: $this->userId,
			byPassParameters: $this->byPassParams,
			skipExchangeSync: $this->skipExchangeSync,
			eventGuid: $this->eventGuid,
			skipBP: $this->skipBP,
			useConsistency: $this->useConsistency,
		);

		$service = Container::getInstance()->getDeleteTaskService();

		try
		{
			$service->delete(
				taskId: $taskId,
				config: $config,
			);
		}
		catch (WrongTaskIdException|TaskNotExistsException|TaskStopDeleteException)
		{
			return false;
		}

		return true;
	}

	private function reset(): void
	{
		$this->eventGuid = null;
		$this->legacyOperationResultData = null;
	}
}
