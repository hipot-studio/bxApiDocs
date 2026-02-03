<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Controller\ValidateDtoTrait;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\TaskDtoMapper;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TaskDto;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Add;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Delete;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Update;
use Bitrix\Tasks\V2\Internal\Entity\Task as TaskEntity;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

#[DtoType(TaskDto::class)]
class Task extends RestController
{
	use ValidateDtoTrait;

	protected ?Context $context = null;

	protected int $userId;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();
		$this->context = new Context($this->userId);

		parent::init();
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IsEnabledFilter(),
		];
	}

	public function updateAction(UpdateRequest $request): UpdateResponse
	{
		if ($request->id === null)
		{
			throw new RequiredFieldInRequestException('id');
		}
		/** @var TaskDto $taskDto */
		$taskDto = $request->fields->getAsDto();
		if (!$this->validateDto($taskDto, 'update'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$taskDto->id = $request->id;
		$taskDtoMapper = new TaskDtoMapper();
		$task = $taskDtoMapper->getTaskByDto($taskDto);
		$accessProvider = new Update();
		if (!$accessProvider->check($task, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$result = (new UpdateTaskCommand(
				task: $task,
				config: new UpdateConfig($this->userId))
			)->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$result->isSuccess())
		{
			throw new DtoValidationException($result->getErrors());
		}

		return new UpdateResponse(true);
	}

	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		if ($request->id === null)
		{
			throw new RequiredFieldInRequestException('id');
		}
		$task = TaskEntity::mapFromId($request->id);
		$accessProvider = new Delete();
		if (!$accessProvider->check($task, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$result = (new DeleteTaskCommand(
				taskId: $task->getId(),
				config: new DeleteConfig($this->userId))
			)->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new DeleteResponse();
	}

	public function addAction(AddRequest $request, TaskProvider $taskProvider): GetResponse
	{
		/** @var TaskDto $addDto */
		$addDto = $request->fields->getAsDto();
		if (!$this->validateDto($addDto, 'add'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$mapper = new TaskDtoMapper();
		$task = $mapper->getTaskByDto($addDto);
		$accessProvider = new Add();

		if (!$accessProvider->check($task, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$result = (new AddTaskCommand(
				task: $task,
				config: new AddConfig($this->userId))
			)->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$entity = $taskProvider->get(
			new TaskParams(
				taskId: $result->getData()['object']->id,
				userId: $this->userId,
			),
		);

		$dto = $mapper->mapByTaskAndRequest($entity);

		return new GetResponse($dto);
	}

	public function getAction(GetRequest $request, TaskProvider $taskProvider): GetResponse
	{
		$accessProvider = new Read();
		if (!$accessProvider->check(new TaskEntity($request->id), $this->context))
		{
			throw new AccessDeniedException();
		}

		$select = $request->select?->getList() ?? [];

		/** @var TaskEntity $entity */
		$entity = $taskProvider->get(
			new TaskParams(
				taskId: $request->id,
				userId: $this->userId,
				group: (bool)$request->getRelation('group'),
				flow: (bool)$request->getRelation('flow'),
				stage: (bool)$request->getRelation('stage'),
				members: ($request->getRelation('responsible')
					|| $request->getRelation('creator')
					|| $request->getRelation('accomplices')
					|| $request->getRelation('auditors')),
				checkLists: (empty($select)
					|| in_array('checklist', $select, true)
					|| in_array('containsChecklist', $select, true)),
				tags: (bool)$request->getRelation('tags'),
				crm: (empty($select) || in_array('crmItemIds', $select, true)),
				email: (bool)$request->getRelation('email'),
				subTasks: empty($select) || in_array('containsSubTasks', $select, true),
				relatedTasks: empty($select) || in_array('containsRelatedTasks', $select, true),
				gantt: empty($select) || in_array('containsGanttLinks', $select, true),
				placements: empty($select) || in_array('containsPlacements', $select, true),
				favorite: empty($select) || in_array('inFavorite', $select, true),
				options: (empty($select)
					|| in_array('inPin', $select, true)
					|| in_array('inGroupPin', $select, true)
					|| in_array('inMute', $select, true)),
				parameters: (empty($select)
					|| in_array('matchesSubTasksTime', $select, true)
					|| in_array('autocompleteSubTasks', $select, true)
					|| in_array('allowsChangeDatePlan', $select, true)
					|| in_array('requireResult', $select, true)
					|| in_array('maxDeadlineChangeDate', $select, true)
					|| in_array('maxDeadlineChanges', $select, true)
					|| in_array('requireDeadlineChangeReason', $select, true)),
				results: empty($select) || in_array('containsResults', $select, true),
				reminders: empty($select) || in_array('numberOfReminders', $select, true),
				userFields: (bool)$request->getRelation('userFields'),
				checkTaskAccess: false,
				scenarios: empty($select) || in_array('scenarios', $select, true),
			),
		);

		if ($entity === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		$mapper = new TaskDtoMapper();
		$dto = $mapper->mapByTaskAndRequest($entity, $request);

		return new GetResponse($dto);
	}
}
