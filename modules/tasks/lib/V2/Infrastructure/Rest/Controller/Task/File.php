<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\Task;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TaskDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\File\AttachFileRequest;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Task\Attachment\Permission\Attach;
use Bitrix\Tasks\V2\Internal\Entity\Task as TaskEntity;
use Bitrix\Tasks\V2\Public\Command\Task\Attachment\AttachFilesCommand;

#[DtoType(TaskDto::class)]
class File extends RestController
{
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

	public function attachAction(AttachFileRequest $request): BooleanResponse
	{
		$task = TaskEntity::mapFromId($request->taskId);
		$accessProvider = new Attach();
		if (!$accessProvider->check($task, $this->context))
		{
			throw new AccessDeniedException();
		}

		foreach ($request->fileIds as $fileId)
		{
			if (gettype($fileId) !== 'integer')
			{
				throw new InvalidRequestFieldTypeException('fileIds', 'integer');
			}
		}

		$commandFileIds = [];

		foreach ($request->fileIds as $fileId)
		{
			$commandFileIds[] = 'n' . $fileId;
		}

		try
		{
			$result = (new AttachFilesCommand(
				taskId: $task->id,
				userId: $this->userId,
				fileIds: $commandFileIds,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new BooleanResponse();
	}
}
