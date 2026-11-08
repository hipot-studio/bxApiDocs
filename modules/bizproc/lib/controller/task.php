<?php

namespace Bitrix\Bizproc\Controller;

use Bitrix\Bizproc;
use Bitrix\Bizproc\Internal\Integration\UI\TaskFileUploaderContext;
use Bitrix\Bizproc\Internal\Integration\UI\UploaderHelper;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\Response\HtmlContent;

class Task extends Base
{
	// the service enforces the same limit: it is the surface any other consumer of the batch goes through
	public const DO_COLLECTION_TASKS_LIMIT = Bizproc\Api\Service\TaskService::DO_TASK_COLLECTION_LIMIT;

	public const ERROR_CODE_EMPTY_TASK_IDS = 'BIZPROC_TASK_COLLECTION_EMPTY_TASK_IDS';
	public const ERROR_CODE_TASKS_LIMIT_EXCEEDED = 'BIZPROC_TASK_COLLECTION_LIMIT_EXCEEDED';

	private const LOGGER_ID = 'bizproc.task_file';

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['doCollection'] = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return $configureActions;
	}

	public function delegateAction(array $taskIds, int $toUserId, int $fromUserId): ?array
	{
		if (!$taskIds)
		{
			$this->addError(new Error(Loc::getMessage('BIZPROC_CONTROLLER_TASK_DELEGATE_EMPTY_TASK_IDS')));

			return null;
		}
		if ($toUserId <= 0 || $fromUserId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('BIZPROC_CONTROLLER_TASK_DELEGATE_INCORRECT_USER_ID')));

			return null;
		}

		$currentUserId = $this->getCurrentUser()->getId();

		$taskService = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($currentUserId)
		);

		$tasksRequest = new Bizproc\Api\Request\TaskService\DelegateTasksRequest($taskIds, $fromUserId, $toUserId, $currentUserId);
		$delegateTaskResult = $taskService->delegateTasks($tasksRequest);

		if (!$delegateTaskResult->isSuccess())
		{
			$this->addErrors($delegateTaskResult->getErrors());

			return null;
		}

		return [
			'message' => $delegateTaskResult->getSuccessDelegateTaskMessage(),
		];
	}

	/**
	 * @internal Experimental, not ready now
	 */
	private function getListAction(?int $targetUserId = null): ?array
	{
		$currentUserId = $this->getCurrentUser()->getId();
		if (!$targetUserId)
		{
			$targetUserId = $currentUserId;
		}

		// todo: check that $targetUserId > 0

		$taskService = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($currentUserId)
		);

		$tasksRequest = new Bizproc\Api\Request\TaskService\GetUserTaskListRequest(
			additionalSelectFields: ['NAME', 'DESCRIPTION'],
			filter: [
				'USER_ID' => $targetUserId,
			],
		);
		$getTasksResult = $taskService->getTasks($tasksRequest);
		if (!$getTasksResult->isSuccess())
		{
			$this->addErrors($getTasksResult->getErrors());

			return null;
		}

		return [
			'tasks' => $getTasksResult->getTasks(),
		];
	}

	public function doAction(int $taskId, ?array $taskRequest = null): ?bool
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$taskService = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($currentUserId)
		);

		if ($taskRequest === null)
		{
			$taskRequest = $this->request->getPostList()->toArray() + $this->request->getFileList()->toArray();
		}

		$pendingFiles = $this->collectPendingTaskFiles($taskId, $taskRequest);

		$request = new Bizproc\Api\Request\TaskService\DoTaskRequest(
			taskId: $taskId,
			userId: $currentUserId,
			taskRequest: $taskRequest,
		);

		$getTasksResult = $taskService->doTask($request);
		if (!$getTasksResult->isSuccess())
		{
			$this->addErrors($getTasksResult->getErrors());

			return null;
		}

		$this->makePendingTaskFilesPersistent($taskId, $pendingFiles);

		return true;
	}

	/**
	 * The temporary files the decision carries, one entry per file field of the task. Read while the task
	 * is still running - a task that is done names no field of its own any more - and pinned only past its
	 * success, so that a task that was not performed leaves nothing behind.
	 *
	 * @return array<int, array{0: TaskFileUploaderContext, 1: string[], 2: int[]}>
	 */
	private function collectPendingTaskFiles(int $taskId, array $taskRequest): array
	{
		$task = \CBPTaskService::getList(
			arFilter: ['ID' => $taskId],
			arSelectFields: ['ACTIVITY', 'PARAMETERS', 'STATUS'],
		)->fetch();

		if (!$task || (int)$task['STATUS'] !== \CBPTaskStatus::Running)
		{
			return [];
		}

		if (!self::savesFieldValues($task, $taskRequest))
		{
			return [];
		}

		$pendingFiles = [];
		$hasFields = isset($taskRequest['fields']);
		foreach (self::getFileControlNames($taskId, $task) as $controlName)
		{
			// the row above is the one the context would read for itself, and its status is checked there
			$context = TaskFileUploaderContext::createFromRunningTask($taskId, $task, $controlName);
			if ($context === null)
			{
				continue;
			}

			$value = $hasFields
				? (is_array($taskRequest['fields']) ? ($taskRequest['fields'][$context->getFieldName()] ?? null) : null)
				: ($taskRequest[$controlName] ?? null)
			;
			if (is_array($value) && \CBPHelper::isAssociativeArray($value))
			{
				// an uploaded file of the previous scheme comes under the same name and carries no token
				continue;
			}

			[$tokens, $fileIds] = UploaderHelper::splitFiles(is_array($value) ? $value : [$value]);
			if ($hasFields)
			{
				$tokens = [];
			}
			if (!$tokens && !$fileIds)
			{
				continue;
			}

			$pendingFiles[] = [$context, $tokens, $fileIds];
		}

		return $pendingFiles;
	}

	/**
	 * Whether the decision saves the values of the fields at all - the condition
	 * RequestInformationOptionalActivity builds the response of the task by. A refusal of a task set not to
	 * save them writes no value into the process, and the cleanup of the module walks the values of the
	 * variables, so a file pinned for such a decision would stay on disk forever.
	 */
	private static function savesFieldValues(array $task, array $taskRequest): bool
	{
		$isRefusal =
			!empty($taskRequest['cancel'])
			|| ($taskRequest['INLINE_USER_STATUS'] ?? null) === \CBPTaskUserStatus::Cancel
		;

		return !$isRefusal || !empty($task['PARAMETERS']['SaveVariables']);
	}

	/**
	 * The names of the controls the file fields of the task are carried by. A field is a file field by its
	 * base type and not by the name of its type.
	 *
	 * @return string[]
	 */
	private static function getFileControlNames(int $taskId, array $task): array
	{
		$documentType = $task['PARAMETERS']['DOCUMENT_TYPE'] ?? null;
		if (!is_array($documentType))
		{
			return [];
		}

		// the same parse the contexts of the fields below are built out of, done once for the whole decision
		$fields = TaskFileUploaderContext::getTaskFields($taskId, $task);

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$controlNames = [];

		foreach ($fields as $field)
		{
			$controlName = is_array($field) ? ($field['FieldId'] ?? null) : null;

			// a field with no type is not resolved at all, as in CBPDocumentService::getFieldInputControl()
			if (!is_string($controlName) || $controlName === '' || (string)($field['Type'] ?? '') === '')
			{
				continue;
			}

			$baseType = $documentService->getFieldTypeObject($documentType, $field)?->getBaseType();
			if ($baseType === Bizproc\FieldType::FILE)
			{
				$controlNames[] = $controlName;
			}
		}

		return $controlNames;
	}

	/**
	 * The task is done and is not taken back for a file left temporary - such a file is removed by the
	 * daily cleanup of the uploader - so both a refusal of the uploader and a raise are only logged.
	 *
	 * @param array<int, array{0: TaskFileUploaderContext, 1: string[], 2: int[]}> $pendingFiles
	 */
	private function makePendingTaskFilesPersistent(int $taskId, array $pendingFiles): void
	{
		foreach ($pendingFiles as [$context, $tokens, $fileIds])
		{
			try
			{
				$leftTemporary = $context->makeFileIdsPersistent($fileIds);
				if ($leftTemporary)
				{
					$this->logFilesLeftTemporary(
						$taskId,
						$context->getFieldName(),
						'the uploader left file IDs temporary: ' . implode(', ', $leftTemporary)
					);
				}
			}
			catch (\Throwable $exception)
			{
				$this->logFilesLeftTemporary(
					$taskId,
					$context->getFieldName(),
					$exception->getMessage(),
					$exception
				);
			}

			try
			{
				$leftTemporary = $context->makePersistent($tokens);
				if ($leftTemporary)
				{
					$this->logFilesLeftTemporary(
						$taskId,
						$context->getFieldName(),
						'the uploader pinned none of the tokens ' . implode(', ', $leftTemporary)
					);
				}
			}
			catch (\Throwable $exception)
			{
				$this->logFilesLeftTemporary(
					$taskId,
					$context->getFieldName(),
					$exception->getMessage(),
					$exception
				);
			}
		}
	}

	private function logFilesLeftTemporary(
		int $taskId,
		string $fieldName,
		string $message,
		?\Throwable $exception = null
	): void
	{
		(new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID)?->error(
			'Files of the field {fieldName} of the done task {taskId} were left temporary: {message}',
			[
				'taskId' => $taskId,
				'fieldName' => $fieldName,
				'message' => $message,
				'exception' => $exception,
			]
		);
	}

	/**
	 * @param mixed[] $taskIds
	 * @param array $taskRequest the same shape as in self::doAction()
	 * @return array{results: array, doneCount: int}|null
	 */
	public function doCollectionAction(array $taskIds, array $taskRequest): ?array
	{
		$currentUserId = (int)$this->getCurrentUser()->getId();

		// the request normalizes the ids, so both checks below read the set that is about to be performed
		$request = new Bizproc\Api\Request\TaskService\DoTaskCollectionRequest(
			taskIds: $taskIds,
			userId: $currentUserId,
			taskRequest: $taskRequest,
		);

		if (!$request->taskIds)
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROC_CONTROLLER_TASK_DO_COLLECTION_EMPTY_TASK_IDS'),
					self::ERROR_CODE_EMPTY_TASK_IDS
				)
			);

			return null;
		}

		if (count($request->taskIds) > self::DO_COLLECTION_TASKS_LIMIT)
		{
			$this->addError(
				new Error(
					Loc::getMessage(
						'BIZPROC_CONTROLLER_TASK_DO_COLLECTION_LIMIT_EXCEEDED',
						['#LIMIT#' => self::DO_COLLECTION_TASKS_LIMIT]
					),
					self::ERROR_CODE_TASKS_LIMIT_EXCEEDED
				)
			);

			return null;
		}

		$service = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($currentUserId),
		);

		$response = $service->doTaskCollection($request);
		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return $response->getData();
	}

	public function getUserTaskByWorkflowIdAction(string $workflowId): ?HtmlContent
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$taskService = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($currentUserId)
		);

		$request = new Bizproc\Api\Request\TaskService\GetUserTaskByWorkflowIdRequest(
			workflowId: $workflowId,
			userId: $currentUserId,
		);

		$userTaskResult = $taskService->getUserTaskByWorkflowId($request);
		if ($userTaskResult->isSuccess())
		{
			return new HtmlContent(
				content: $userTaskResult->getContent(),
				additionalResponseParams: $userTaskResult->getTask(),
			);

		}

		$this->addErrors($userTaskResult->getErrors());

		return null;
	}

	/**
	 * @param numeric[] $taskIds
	 * @param int $newStatus
	 * @return bool|null
	 */
	public function doInlineTasksAction(array $taskIds, int $newStatus): ?bool
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$preparedTaskIds = [];
		foreach ($taskIds as $id)
		{
			if (is_numeric($id))
			{
				$preparedTaskIds[] = (int)$id;
			}
		}

		$request = new Bizproc\Api\Request\TaskService\DoInlineTasksRequest(
			taskIds: $preparedTaskIds,
			userId: $currentUserId,
			newTaskStatusId: $newStatus,
		);

		$service = new Bizproc\Api\Service\TaskService(
			new Bizproc\Api\Service\TaskAccessService($request->userId),
		);

		$response = $service->doInlineTasks($request);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());
			return null;
		}

		return true;
	}
}
