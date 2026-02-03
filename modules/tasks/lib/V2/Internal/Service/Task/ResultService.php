<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\UrlPreview\UrlPreview;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Exception\Task\ResultNotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\MessageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\AutomationService;
use Bitrix\Tasks\V2\Internal\Service\PushService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\ParticipantTrait;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use CUserTypeManager;
use InvalidArgumentException;

class ResultService
{
	use ParticipantTrait;

	public const COMMAND_CREATE = 'task_result_create';
	public const COMMAND_DELETE = 'task_result_delete';
	public const COMMAND_UPDATE = 'task_result_update';

	public const RESULT_ADD = 'RESULT';
	public const RESULT_EDIT = 'RESULT_EDIT';
	public const RESULT_REMOVE = 'RESULT_REMOVE';

	public function __construct(
		private readonly UpdateTaskService $updateTaskService,
		private readonly PushService $pushService,
		private readonly AutomationService $automationService,
		private readonly TaskResultRepositoryInterface $taskResultRepository,
		private readonly TaskLogRepositoryInterface $taskLogRepository,
		private readonly MessageRepositoryInterface $messageRepository,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly ChatNotificationInterface $chatNotification,
	)
	{
	}

	public function isResultRequired(int $taskId): bool
	{
		return $this->taskResultRepository->isResultRequired($taskId);
	}

	public function require(int $taskId, int $userId, bool $require = true, bool $useConsistency = false): Task
	{
		$task = $this->getTask($taskId)->cloneWith(['requireResult' => $require]);

		if ($require)
		{
			$this->chatNotification->notify(
				NotificationType::ResultRequested,
				$task,
				[
					'triggeredBy' => $this->userRepository->getByIds([$userId])->findOneById($userId)
				]
			);
		}

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		return $this->updateTaskService->update($task, $config);
	}

	public function createFromMessage(int $messageId, int $userId): Result
	{
		$message = $this->messageRepository->getById($messageId);
		if ($message === null)
		{
			throw new InvalidArgumentException('Message not found');
		}

		if (!$message->chat?->isTaskChat())
		{
			throw new InvalidArgumentException('Message is not from task chat');
		}

		$fileIds = $message->fileIds;
		if ($fileIds !== null)
		{
			// add 'n' to create attachments based on file ids from message
			$fileIds = array_map(static fn (int $fileId): string => 'n' . $fileId, $fileIds);
		}

		$result = new Result(
			taskId: $message->chat->entityId,
			text: !empty($message->text) ? $message->text : Loc::getMessage('TASKS_RESULT_SERVICE_DEFAULT_TITLE_FROM_MESSAGE'),
			fileIds: $fileIds,
			previewId: $message->previewId,
			messageId: $message->getId(),
		);

		return $this->create($result, $userId);
	}

	public function create(Result $result, int $userId, bool $skipNotification = false): Result
	{
		if ($result->taskId === null)
		{
			throw new InvalidArgumentException('Task id must be set');
		}

		// check if task exists
		$this->getTask((int)$result->taskId);

		$author = $this->userRepository->getByIds([$userId])->findOneById($userId);

		$resultToAdd = new Result(
			taskId: $result->taskId,
			text: $result->text,
			author: $author,
			createdAtTs: time(),
			updatedAtTs: time(),
			status: Result\Status::Open,
			type: $result->type,
			fileIds: $result->fileIds,
			previewId: $result->previewId,
			messageId: $result->messageId,
		);

		$resultId = $this->taskResultRepository->save($resultToAdd, $userId);

		$result = Result::mapFromArray([...$resultToAdd->toArray(), 'id' => $resultId]);

		$historyLog = new HistoryLog(
			userId: $userId,
			taskId: $result->taskId,
			field: static::RESULT_ADD,
			toValue: $result->id,
		);

		$this->taskLogRepository->add($historyLog);

		$this->executeAutomationTrigger($result->taskId, $result);

		if (!$skipNotification)
		{
			$notifyType = $result->messageId
				? NotificationType::ResultFromMessage
				: NotificationType::ResultAdded
			;
			$this->notifyChat($result, $userId, $notifyType);
		}

		$uf = [];
		if (!empty($result->fileIds))
		{
			$uf[UserField::TASK_RESULT] = (array)$result->fileIds;
		}

		if ($result->previewId)
		{
			$uf[UserField::TASK_RESULT_PREVIEW] = (new Signer())->sign((string)$result->previewId, UrlPreview::SIGN_SALT);
		}

		if (!empty($uf))
		{
			$this->getUfManager()->Update(ResultTable::getUfId(), $result->getId(), $uf);
		}

		$this->sendPush(self::COMMAND_CREATE, $result);

		return $result;
	}

	public function update(Result $result, int $userId): Result
	{
		if ($result->id === null)
		{
			throw new InvalidArgumentException('Result id must be set');
		}

		$resultInDb = $this->taskResultRepository->getById($result->id);
		if (!$resultInDb)
		{
			throw new ResultNotFoundException('Result not found');
		}

		$updatedResult = Result::mapFromArray([
			...$resultInDb->toArray(),
			...array_filter(
				$result->toArray(),
				static fn($value) => $value !== null
			),
		]);

		$this->taskResultRepository->save($updatedResult, $userId);

		$historyLog = new HistoryLog(
			userId: $userId,
			taskId: $updatedResult->taskId,
			field: static::RESULT_EDIT,
			toValue: $updatedResult->id,
		);

		$this->taskLogRepository->add($historyLog);

		$this->executeAutomationTrigger($updatedResult->taskId, $updatedResult);

		$this->notifyChat($updatedResult, $userId, NotificationType::ResultModified);

		$uf = [];
		if ($result->fileIds !== null)
		{
			$uf[UserField::TASK_RESULT] = $result->fileIds;
		}

		if ($result->previewId)
		{
			$uf[UserField::TASK_RESULT_PREVIEW] = (new Signer())->sign((string)$result->previewId, UrlPreview::SIGN_SALT);
		}

		if (!empty($uf))
		{
			$this->getUfManager()->Update(ResultTable::getUfId(), $result->getId(), $uf);

			$fileIds = $this->taskResultRepository->getAttachmentIdsByResult($result->getId());

			$updatedResult = Result::mapFromArray([...$updatedResult->toArray(), 'fileIds' => $fileIds]);
		}

		$this->sendPush(self::COMMAND_UPDATE, $updatedResult);

		return $updatedResult;
	}

	public function close(int $taskId, int $userId): void
	{
		$resultsCollection = $this->taskResultRepository->getByTask($taskId);

		if ($resultsCollection->isEmpty())
		{
			return;
		}

		foreach ($resultsCollection as $result)
		{
			if ($result->status === Result\Status::Open)
			{
				$updatedResult = Result::mapFromArray([...$result->toArray(), 'status' => Result\Status::Closed->value]);
				$this->taskResultRepository->save($updatedResult, $userId);
			}
		}
	}

	public function deleteByTaskId(int $taskId, int $userId): void
	{
		$resultsCollection = $this->taskResultRepository->getByTask($taskId);

		foreach ($resultsCollection as $result)
		{
			$this->deleteInternal($result, $userId);
		}
	}

	public function delete(int $resultId, int $userId): void
	{
		$result = $this->taskResultRepository->getById($resultId);
		if ($result === null)
		{
			return;
		}

		$this->deleteInternal($result, $userId);

		$this->notifyChat($result, $userId, NotificationType::ResultDeleted);
	}

	public function deleteMessageLink(int $messageId): void
	{
		$this->taskResultRepository->deleteMessageLink($messageId);
	}

	public function getLastResult(int $taskId): ?Result
	{
		return $this->taskResultRepository->getByTask($taskId)->findOneById($taskId);
	}

	public function isExists(int $taskId, int $commentId = 0): bool
	{
		return $this->taskResultRepository->isExists($taskId, $commentId);
	}

	protected function sendPush(string $command, Result $result): void
	{
		$compatibilityRepository = Container::getInstance()->getTaskCompatabilityRepository();

		$fullTaskData = $compatibilityRepository->getTaskData($result->taskId);

		$participants = $this->getParticipants($fullTaskData);

		$recipients = UserCollection::mapFromIds($participants);

		$lastResult = $this->getLastResult($result->taskId);

		$this->pushService->addEvent($recipients, [
			'module_id' => $this->pushService->getModuleName(),
			'command' => $command,
			'params' => [
				'result' => $result->toArray(),
				'taskId' => $result->taskId,
				'taskRequireResult' => $this->isResultRequired($result->taskId) ? "Y" : "N",
				'taskHasResult' => $lastResult ? "Y" : "N",
				'taskHasOpenResult' => ($lastResult && $lastResult->status === Result\Status::Open) ? "Y" : "N",
			],
		]);
	}

	protected function executeAutomationTrigger(int $taskId, Result $result): void
	{
		$this->automationService->onTaskFieldChanged(
			taskId: $taskId,
			updatedFields: ['COMMENT_RESULT' => $result->text],
		);
	}

	protected function getUfManager(): CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}

	protected function deleteInternal(Result $result, int $userId): void
	{
		$this->taskResultRepository->delete($result->id, $userId);

		$historyLog = new HistoryLog(
			userId: $userId,
			taskId: $result->taskId,
			field: static::RESULT_REMOVE,
			toValue: $result->id,
		);

		$this->taskLogRepository->add($historyLog);

		$this->sendPush(self::COMMAND_DELETE, $result);
	}

	private function notifyChat(Result $result, int $userId, NotificationType $notificationType): void
	{
		$task = $this->getTask((int)$result->taskId);

		$triggeredBy = $this->userRepository->getByIds([$userId])->findOneById($userId);

		$this->chatNotification->notify(
			type: $notificationType,
			task: $task,
			args: [
				'triggeredBy' => $triggeredBy,
				'resultText' => $result->getText(),
				'dateTs' => $result->createdAtTs,
				'fileIds' => $result->fileIds ?? [],
				'messageId' => $result->messageId ?? 0,
				'type' => $result->type ?? null,
			],
		);
	}

	private function getTask(int $taskId): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new InvalidArgumentException('Task not found');
		}

		return $task;
	}
}
