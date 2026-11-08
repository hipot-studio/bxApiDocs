<?php

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Data\TaskService\TasksToGet;
use Bitrix\Bizproc\Api\Data\TaskService\TasksToBeDelegated;
use Bitrix\Bizproc\Api\Request\TaskService\DelegateTasksRequest;
use Bitrix\Bizproc\Api\Request\TaskService\DoInlineTasksRequest;
use Bitrix\Bizproc\Api\Request\TaskService\DoTaskCollectionRequest;
use Bitrix\Bizproc\Api\Request\TaskService\DoTaskRequest;
use Bitrix\Bizproc\Api\Request\TaskService\GetUserTaskRequest;
use Bitrix\Bizproc\Api\Request\TaskService\GetUserTaskListRequest;
use Bitrix\Bizproc\Api\Request\TaskService\GetUserTaskByWorkflowIdRequest;
use Bitrix\Bizproc\Api\Response;
use Bitrix\Bizproc\Api\Response\TaskService\DelegateTasksResponse;
use Bitrix\Bizproc\Api\Response\TaskService\DoTaskCollectionResponse;
use Bitrix\Bizproc\Api\Response\TaskService\DoTaskResponse;
use Bitrix\Bizproc\Api\Response\TaskService\GetUserTaskListResponse;
use Bitrix\Bizproc\Api\Response\TaskService\GetUserTaskByWorkflowIdResponse;
use Bitrix\Bizproc\BaseType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Integration\UI\TaskFileUploaderContext;
use Bitrix\Bizproc\Public\Service\Task\UnArchiveTaskService;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Bizproc\Workflow\Task\TaskTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

class TaskService
{
	public const DO_TASK_COLLECTION_LIMIT = 50;

	private const TASK_ALREADY_DONE_ERROR_CODE = 'TASK_ALREADY_DONE';
	private const TASKS_LIMIT_EXCEEDED_ERROR_CODE = 'TASKS_LIMIT_EXCEEDED';
	private const TASK_USER_NOT_MEMBER_ERROR_CODE = 'TASK_USER_NOT_MEMBER';
	private const TASK_NOT_FOUND_ERROR_CODE = 'TASK_NOT_FOUND';
	private const TASK_FILE_FIELD_NOT_SHARED_ERROR_CODE = 'TASK_FILE_FIELD_NOT_SHARED';

	public function __construct(
		private TaskAccessService $accessService
	)
	{}

	public function delegateTasks(DelegateTasksRequest $request): DelegateTasksResponse
	{
		$delegateResponse = new DelegateTasksResponse();

		$tasksToDelegate = null;
		try
		{
			$tasksToDelegate = TasksToBeDelegated::createFromRequest($request);
		}
		catch (ArgumentOutOfRangeException $e)
		{
			$errorMessage =
				in_array($e->getParameter(), ['fromUserId', 'toUserId'], true)
					? Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_DELEGATE_TASK_ERROR_INCORRECT_USER_ID')
					: $e->getMessage()
			;
			$delegateResponse->addError(new Error($errorMessage));
		}
		catch (ArgumentException $e)
		{
			$delegateResponse->addError(
				new Error(Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_DELEGATE_TASK_ERROR_NO_TASKS'))
			);
		}
		if (!$tasksToDelegate || !$delegateResponse->isSuccess())
		{
			return $delegateResponse;
		}

		$checkAccessResult = $this->accessService->checkDelegateTask(
			$tasksToDelegate->getToUserId(),
			$tasksToDelegate->getFromUserId()
		);
		if (!$checkAccessResult->isSuccess())
		{
			return $delegateResponse->addErrors($checkAccessResult->getErrors());
		}

		$allowedDelegationType = $checkAccessResult->getAllowedDelegationTypes();

		$errors = [];
		$isDelegated = \CBPDocument::delegateTasks(
			$tasksToDelegate->getFromUserId(),
			$tasksToDelegate->getToUserId(),
			$tasksToDelegate->getTaskIds(),
			$errors,
			$allowedDelegationType
		);

		if (!$isDelegated && !$errors)
		{
			$errors = [Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_DELEGATE_TASK_ERROR_TASKS_NOT_FOUND')];
		}
		foreach ($errors as $errorMessage)
		{
			$delegateResponse->addError(new Error($errorMessage));
		}

		if ($delegateResponse->isSuccess())
		{
			$delegateResponse->setData([
				'successMessage' => Loc::getMessage(
					'BIZPROC_LIB_API_TASK_SERVICE_DELEGATE_TASK_SUCCESS_MESSAGE',
					['#USER_NAME#' => \CBPHelper::convertUserToPrintableForm($tasksToDelegate->getToUserId(), '', false)]
				),
			]);
		}

		return $delegateResponse;
	}

	public function getTasks(GetUserTaskListRequest $request): GetUserTaskListResponse
	{
		$getTasksResponse = new GetUserTaskListResponse();

		$tasksToGet = null;
		try
		{
			$tasksToGet = TasksToGet::createFromRequest($request);
		}
		catch (ArgumentOutOfRangeException $exception)
		{
			// todo: user friendly error message
			// use ::createError?
			$getTasksResponse->addError(new Error($exception->getMessage()));
		}
		if (!$tasksToGet || !$getTasksResponse->isSuccess())
		{
			return $getTasksResponse;
		}

		$checkAccessResult = $this->accessService->checkViewTasks($tasksToGet->getTargetUserId());
		if (!$checkAccessResult->isSuccess())
		{
			return $getTasksResponse->addErrors($checkAccessResult->getErrors());
		}

		$tasksIterator = \CBPTaskService::getList(
			$tasksToGet->getSort(),
			$tasksToGet->getFilter(),
			false,
			[
				'nPageSize' => $tasksToGet->getLimit(),
				'iNumPage' => (int)($tasksToGet->getOffset() / $tasksToGet->getLimit()) + 1,
			],
			$tasksToGet->buildSelectFields()
		);
		$taskList = [];
		while ($task = $tasksIterator->fetch())
		{
			$taskList[] = $this->prepareTaskInfo($task, $tasksToGet->getTargetUserId());
		}

		return $getTasksResponse->setData(['tasks' => $taskList]);
	}

	public function doTask(DoTaskRequest $request)
	{
		$result = new Response\TaskService\DoTaskResponse();
		$task = false;

		$checkAccessResult = $this->accessService->checkDoTasks($request->userId);
		if (!$checkAccessResult->isSuccess())
		{
			return $result->addErrors($checkAccessResult->getErrors());
		}

		if ($request->taskId > 0)
		{
			$task = \CBPTaskService::GetList(
				[],
				[
					'ID' => $request->taskId,
					'USER_ID' => $request->userId
				],
				false,
				false,
				[
					'ID',
					'WORKFLOW_ID',
					'ACTIVITY',
					'ACTIVITY_NAME',
					'MODIFIED',
					'OVERDUE_DATE',
					'NAME',
					'DESCRIPTION',
					'PARAMETERS',
					'USER_STATUS',
				]
			)->fetch();
		}

		if (!$task)
		{
			$this->addUserTaskNotFoundError($result, $request->taskId, $request->userId);

			return $result;
		}

		if ((int)$task['USER_STATUS'] !== \CBPTaskUserStatus::Waiting)
		{
			return $result->addError(
				new Error(
					Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_DO_TASK_ERROR_ALREADY_DONE'),
					self::TASK_ALREADY_DONE_ERROR_CODE
				)
			);
		}

		$task['PARAMETERS']['DOCUMENT_ID'] = \CBPStateService::GetStateDocumentId($task['WORKFLOW_ID']);
		$task['MODULE_ID'] = $task['PARAMETERS']['DOCUMENT_ID'][0];
		$task['ENTITY'] = $task['PARAMETERS']['DOCUMENT_ID'][1];
		$task['DOCUMENT_ID'] = $task['PARAMETERS']['DOCUMENT_ID'][2];

		$isPosted = \CBPDocument::PostTaskForm($task, $request->userId, $request->taskRequest, $errors);

		// the decision is over and the status of the task could have turned, so the row read while the
		// values of its fields were being taken apart answers for it no more
		TaskFileUploaderContext::forgetTask($request->taskId);

		if (!$isPosted)
		{
			$error = reset($errors);
			if ($task['MODULE_ID'] === 'rpa' && $error['code'] === \CBPRuntime::EXCEPTION_CODE_INSTANCE_TERMINATED)
			{
				return $result;
			}

			if (!empty($error['customData']))
			{
				foreach ($errors as $error)
				{
					$result->addError(new Error($error['message'], 0, $error['customData']));
				}

				return $result;
			}

			$result->addError(new Error($error['message']));

			if ($error && $error['code'] === \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND)
			{
				\CBPTaskService::deleteByWorkflow($task['WORKFLOW_ID'], \CBPTaskStatus::Running);
			}
		}

		return $result;
	}

	/**
	 * Runs tasks one by one and reports the outcome of every single task.
	 * The batch is not transactional: a done task continues its workflow and cannot be rolled back,
	 * so a batch larger than self::DO_TASK_COLLECTION_LIMIT is refused before the first task runs.
	 * The limit counts the ids of the request, which are already normalized by the request itself:
	 * repeated and discarded values do not spend it, and every task gets a single outcome record.
	 */
	public function doTaskCollection(DoTaskCollectionRequest $request): DoTaskCollectionResponse
	{
		$response = new DoTaskCollectionResponse();

		if (count($request->taskIds) > self::DO_TASK_COLLECTION_LIMIT)
		{
			return $response->addError(
				new Error(
					Loc::getMessage(
						'BIZPROC_LIB_API_TASK_SERVICE_DO_TASK_COLLECTION_ERROR_LIMIT_EXCEEDED',
						['#LIMIT#' => self::DO_TASK_COLLECTION_LIMIT]
					),
					self::TASKS_LIMIT_EXCEEDED_ERROR_CODE
				)
			);
		}

		$checkAccessResult = $this->accessService->checkDoTasks($request->userId);
		if (!$checkAccessResult->isSuccess())
		{
			return $response->addErrors($checkAccessResult->getErrors());
		}

		$tasks = $this->readTasksOfCollection($request->taskIds);

		foreach ($request->taskIds as $taskId)
		{
			if ($this->carriesFileFieldValue($tasks[$taskId] ?? null, $request->taskRequest))
			{
				$response->addTaskResult(
					$taskId,
					DoTaskCollectionResponse::STATUS_ERROR,
					[[
						'message' => Loc::getMessage(
							'BIZPROC_LIB_API_TASK_SERVICE_DO_TASK_COLLECTION_ERROR_FILE_FIELD'
						),
						'code' => self::TASK_FILE_FIELD_NOT_SHARED_ERROR_CODE,
						'fieldId' => null,
					]]
				);

				continue;
			}

			$doTaskResponse = $this->doTask(new DoTaskRequest($taskId, $request->userId, $request->taskRequest));
			$status = $this->resolveDoTaskStatus($doTaskResponse, $request->userId);

			$response->addTaskResult(
				$taskId,
				$status,
				$status === DoTaskCollectionResponse::STATUS_ERROR
					? $this->extractTaskErrors($doTaskResponse)
					: []
			);
		}

		return $response;
	}

	/**
	 * The rows the check below is fed with, the whole set in one select instead of a select per task; the
	 * set is capped by DO_TASK_COLLECTION_LIMIT. The `@` prefix of a filter is understood by
	 * CBPHelper::prepareSql and unfolds into a condition over the primary key of the whole list.
	 *
	 * @param int[] $taskIds
	 * @return array<int, array> the rows by the id of the task; a task that is gone leaves no row
	 */
	private function readTasksOfCollection(array $taskIds): array
	{
		if (!$taskIds)
		{
			return [];
		}

		$tasks = [];
		$iterator = \CBPTaskService::getList(
			arFilter: ['@ID' => $taskIds],
			arSelectFields: ['ID', 'ACTIVITY', 'PARAMETERS']
		);

		while ($task = $iterator->fetch())
		{
			$tasks[(int)$task['ID']] = $task;
		}

		return $tasks;
	}

	/**
	 * Whether the shared decision names a file field of this task. A group decision is one request applied
	 * to every task of the set, so such a value would attach one file to all of them - and dropping it
	 * silently would let the task go without the file nobody meant to leave out. The task is answered with
	 * an error of its own instead; a task whose file field the decision says nothing about runs as it is.
	 */
	private function carriesFileFieldValue(?array $task, array $taskRequest): bool
	{
		// the document type of the fields is written to the parameters by the activity that asks for them
		$documentType = is_array($task) ? ($task['PARAMETERS']['DOCUMENT_TYPE'] ?? null) : null;
		if (!is_array($documentType))
		{
			return false;
		}

		$taskId = (int)($task['ID'] ?? 0);
		if ($taskId <= 0)
		{
			return false;
		}

		$fields = TaskFileUploaderContext::getTaskFields($taskId, $task);
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$decisionFields = is_array($taskRequest['fields'] ?? null) ? $taskRequest['fields'] : [];

		foreach ($this->fillFieldsBaseType($documentService, $documentType, $fields) as $field)
		{
			if (($field['BaseType'] ?? null) !== FieldType::FILE)
			{
				continue;
			}

			// the code of the field addresses the value in the fields of a decision, the name of its control
			// addresses the same value in a decision posted as a form
			$code = $field['Id'] ?? null;
			$controlName = $field['FieldId'] ?? null;

			if (
				(is_string($code) && array_key_exists($code, $decisionFields))
				|| (is_string($controlName) && array_key_exists($controlName, $taskRequest))
			)
			{
				return true;
			}
		}

		return false;
	}

	private function resolveDoTaskStatus(DoTaskResponse $response, int $userId): string
	{
		if ($response->isSuccess())
		{
			return DoTaskCollectionResponse::STATUS_DONE;
		}

		$notFoundCodes = [
			self::TASK_NOT_FOUND_ERROR_CODE,
			self::TASK_ALREADY_DONE_ERROR_CODE,
			self::TASK_USER_NOT_MEMBER_ERROR_CODE,
		];
		foreach ($response->getErrors() as $error)
		{
			if (in_array($error->getCode(), $notFoundCodes, true))
			{
				return DoTaskCollectionResponse::STATUS_NOT_FOUND;
			}
		}

		// access may be revoked between the check above and this task
		if (!$this->accessService->checkDoTasks($userId)->isSuccess())
		{
			return DoTaskCollectionResponse::STATUS_ACCESS_DENIED;
		}

		return DoTaskCollectionResponse::STATUS_ERROR;
	}

	/**
	 * @return array<int, array{message: string, code: string|null, fieldId: string|null}>
	 */
	private function extractTaskErrors(DoTaskResponse $response): array
	{
		$errors = [];

		foreach ($response->getErrors() as $error)
		{
			$code = $error->getCode();
			$customData = $error->getCustomData();

			$errors[] = [
				'message' => (string)$error->getMessage(),
				'code' => ($code === null || $code === 0 || $code === '') ? null : (string)$code,
				'fieldId' => (is_string($customData) && $customData !== '') ? $customData : null,
			];
		}

		return $errors;
	}

	public function doInlineTasks(DoInlineTasksRequest $request): Response\TaskService\DoInlineTaskResponse
	{
		$taskIds = [];
		foreach ($request->taskIds as $id)
		{
			// TODO - throw argument or return error if $id not integer?
			if (is_int($id))
			{
				$taskIds[] = $id;
			}
		}

		$result = new Response\TaskService\DoInlineTaskResponse();
		if ($taskIds)
		{
			$errors = [];
			\CBPDocument::setTasksUserStatus($request->userId, $request->newTaskStatusId, $taskIds, $errors);

			if ($errors)
			{
				foreach ($errors as $errorMessage)
				{
					$result->addError(new \Bitrix\Bizproc\Error($errorMessage));
				}
			}
		}

		return $result;
	}

	public function getUserTask(GetUserTaskRequest $request): Response\TaskService\GetUserTaskResponse
	{
		$response = new Response\TaskService\GetUserTaskResponse();

		if ($request->taskId <= 0 || $request->userId <= 0)
		{
			$this->addUserTaskNotFoundError($response, 0, $request->userId);

			return $response;
		}

		$checkAccessResult = $this->accessService->checkViewTasks($request->userId);
		if (!$checkAccessResult->isSuccess())
		{
			return $response->addErrors($checkAccessResult->getErrors());
		}

		$task = \CBPTaskService::getList(
			[],
			['ID' => $request->taskId, 'USER_ID' => $request->userId],
			false,
			false,
			[
				'ID',
				'USER_ID',
				'NAME',
				'DESCRIPTION',
				'WORKFLOW_ID',
				'ACTIVITY',
				'STATUS',
				'USER_STATUS',
				'IS_INLINE',
				'PARAMETERS',
				'DELEGATION_TYPE',
			],
		)->fetch();

		if (!$task)
		{
			$archive =
				TaskArchiveTable::query()
					->setSelect(['TASKS_DATA', 'WORKFLOW_ID'])
					->where('TASKS.TASK_ID', $request->taskId)
					->setLimit(1)
					->fetchObject()
			;

			if ($archive)
			{
				$unArchiveTaskService = new UnArchiveTaskService($archive->getTasksData());
				$taskData = $unArchiveTaskService->getTask($request->taskId);
				$neededTaskUser = current(array_filter($taskData['USERS'], fn($taskUser) => $taskUser['USER_ID'] === $request->userId));
				$userStatus = $neededTaskUser['STATUS'];

				$task = [
					'ID' => $taskData['ID'],
					'NAME' => $taskData['NAME'],
					'WORKFLOW_ID' => $archive->getWorkflowId(),
					'DESCRIPTION' => $taskData['DESCRIPTION'],
					'STATUS' => $taskData['STATUS'],
					'USER_STATUS' => $userStatus,
					'DELEGATION_TYPE' => null,
				];
			}
		}

		if (!$task)
		{
			$this->addUserTaskNotFoundError($response, $request->taskId, $request->userId);

			return $response;
		}

		$task = $this->prepareTaskInfo($task, $request->userId);

		return $response->setTask($task);
	}

	public function getUserTaskByWorkflowId(GetUserTaskByWorkflowIdRequest $request): GetUserTaskByWorkflowIdResponse
	{
		$response = new GetUserTaskByWorkflowIdResponse();
		$renderer = new \Bitrix\Bizproc\Controller\Response\RenderControlCollectionContent();
		$task = false;
		$taskQuery = null;

		if ($request->workflowId)
		{
			$taskQuery =
				\Bitrix\Bizproc\Workflow\Task\TaskTable::query()
					->setSelect([
						'ID',
						'ACTIVITY',
						'ACTIVITY_NAME',
						'NAME',
						'DESCRIPTION',
						'PARAMETERS',
						'TEMPLATE_ID' => 'WORKFLOW_STATE.WORKFLOW_TEMPLATE_ID',
					])
					->setFilter([
						'=WORKFLOW_ID' => $request->workflowId,
						'=TASK_USERS.USER_ID' => $request->userId,
						'=TASK_USERS.STATUS' => \CBPTaskUserStatus::Waiting,
					])
					->setOrder(['ID' => 'DESC'])
					->exec()
			;
			$task = $taskQuery->fetch();
		}

		if (!$task)
		{
			$response->setContent($renderer);

			return $response;
		}

		if (!isset($task['PARAMETERS']['DOCUMENT_ID']))
		{
			$documentId = \CBPStateService::getStateDocumentId($request->workflowId);
			if ($documentId === false)
			{
				$response->setContent($renderer);

				return $response;
			}

			$task['PARAMETERS']['DOCUMENT_ID'] = $documentId;
		}

		$task['saveVariables'] = $task['PARAMETERS']['SaveVariables'] ?? false;
		$task['commentRequired'] = $task['PARAMETERS']['CommentRequired'] ?? 'N';
		$controls = \CBPDocument::getTaskControls($task, $request->userId);

		$task['BUTTONS'] = $controls['BUTTONS'] ?? null;
		$task['FIELDS'] = $controls['FIELDS'] ?? null;
		if (isset($task['DESCRIPTION']))
		{
			$task['DESCRIPTION'] = \CBPViewHelper::prepareTaskDescription(
				\CBPHelper::convertBBtoText(
					preg_replace('|\n+|', "\n", trim($task['DESCRIPTION']))
				)
			);
		}

		if ($taskQuery?->fetch())
		{
			$task['IS_LAST_TASK_FOR_USER'] = false;
		}
		else
		{
			$task['IS_LAST_TASK_FOR_USER'] = \CBPTaskService::isLastTaskForUserByActivity(
				$task['ACTIVITY_NAME'],
				$request->userId,
				$task['TEMPLATE_ID'],
				$task['ACTIVITY']
			);
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$documentType = $documentService->getDocumentType($task['PARAMETERS']['DOCUMENT_ID']);
		if (isset($task['FIELDS']) && is_array($task['FIELDS']))
		{
			foreach ($task['FIELDS'] as $field)
			{
				$params['Field'] = $field['FieldId'] ?? $field['Id'];
				$params['Value'] = $field['Default'] ?? null;
				$params['Als'] = false;
				$params['RenderMode'] = 'public';

				$renderer->addProperty(
					$documentType,
					BaseType\File::fillTaskIdSetting($documentService, $documentType, $field, (int)$task['ID']),
					$params
				);
			}

			$task['FIELDS'] = $this->fillFieldsBaseType($documentService, $documentType, $task['FIELDS']);
		}
		$response->setTask($task);
		$response->setContent($renderer);

		return $response;
	}

	/**
	 * The type of Bitrix\Bizproc\BaseType the control of a field is drawn by, so that an alias of a type
	 * (S:DateTime of a list, UF:date of a custom field) is known to the client too. Done past the render,
	 * so that the key does not reach getFieldInputControl() and leak into the markup.
	 */
	private function fillFieldsBaseType(
		\CBPDocumentService $documentService,
		array $documentType,
		array $fields
	): array
	{
		foreach ($fields as $index => $field)
		{
			// a field with no type is not resolved at all, as in CBPDocumentService::getFieldInputControl()
			if ((string)($field['Type'] ?? '') === '')
			{
				continue;
			}

			$baseType = $documentService->getFieldTypeObject($documentType, $field)?->getBaseType();
			if ($baseType !== null)
			{
				$fields[$index]['BaseType'] = $baseType;
			}
		}

		return $fields;
	}

	private function addUserTaskNotFoundError(Result $response, int $taskId, int $userId): void
	{
		if ($taskId > 0)
		{
			$task = TaskTable::getByPrimary($taskId)->fetch();
			if ($task)
			{
				if ((int)$task['STATUS'] !== \CBPTaskStatus::Running)
				{
					$response->addError(
						new Error(
							Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_ERROR_TASK_ALREADY_DONE'),
							self::TASK_ALREADY_DONE_ERROR_CODE
						)
					);
				}
				elseif ($this->accessService->isCurrentUser($userId))
				{
					$response->addError(
						new Error(
							Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_ERROR_CURRENT_USER_NOT_MEMBER'),
							self::TASK_USER_NOT_MEMBER_ERROR_CODE,
						)
					);
				}
				else
				{
					$response->addError(
						new Error(
							Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_ERROR_TARGET_USER_NOT_MEMBER'),
							self::TASK_USER_NOT_MEMBER_ERROR_CODE,
						)
					);
				}

				return;
			}
		}

		$response->addError(
			new Error(
				Loc::getMessage('BIZPROC_LIB_API_TASK_SERVICE_DO_TASK_ERROR_NO_TASK'),
				self::TASK_NOT_FOUND_ERROR_CODE
			)
		);
	}

	private function prepareTaskInfo(array $task, int $userId = 0): array
	{
		$task['STATUS'] = (int)$task['STATUS'];

		if (isset($task['MODIFIED']))
		{
			$task['MODIFIED'] = FormatDateFromDB($task['MODIFIED']);
		}

		$documentId =
			is_array($task['PARAMETERS']['DOCUMENT_ID'] ?? null)
				? $task['PARAMETERS']['DOCUMENT_ID']
				: null
		;
		$task['DOCUMENT_URL'] = $documentId ? \CBPDocument::getDocumentAdminPage($documentId) : '';

		$task['MODULE_ID'] = $documentId ? $documentId[0] : '';
		$task['ENTITY'] = $documentId ? $documentId[1] : '';
		$task['DOCUMENT_ID'] = $documentId ? $documentId[2] : '';
		$task['COMPLEX_DOCUMENT_ID'] = $documentId;

		if (isset($task['WORKFLOW_TEMPLATE_NAME']))
		{
			$task['WORKFLOW_NAME'] = $task['WORKFLOW_TEMPLATE_NAME']; // compatibility
		}
		if (isset($task['WORKFLOW_STARTED']))
		{
			$task['WORKFLOW_STARTED'] = FormatDateFromDB($task['WORKFLOW_STARTED']);
		}

		$controls = \CBPDocument::getTaskControls($task, $userId);

		$task['BUTTONS'] = $controls['BUTTONS'] ?? null;
		$task['FIELDS'] = $controls['FIELDS'] ?? null;

		if (isset($task['DELEGATION_TYPE']))
		{
			$task['DELEGATION_TYPE'] = (int)$task['DELEGATION_TYPE'];
		}

		return $task;
	}
}
