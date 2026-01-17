<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Compatibility\Rest\Task\Chat;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Util\Error;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Integration;
use Bitrix\Tasks\V2\Public\Command\Rest\Task\Chat\SendMessageCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class AddCommentFacade
{
	private const FIELD_AUTHOR = 'AUTHOR_ID';
	private const FIELD_MESSAGE = 'POST_MESSAGE';

	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
	)
	{
	}

	public function addComment(TaskParams $taskParams, array $requestParams): Result
	{
		$addResult = new Result();

		$requestParams = $this->normalizeFields($taskParams, $requestParams);

		$validateResult = $this->validateFields($taskParams, $requestParams);
		if (!$validateResult->isSuccess())
		{
			$addResult->addErrors($validateResult->getErrors());

			return $addResult;
		}

		$commandResult = $this->add($taskParams, $requestParams);
		if ($commandResult->isSuccess())
		{
			$addResult->setData($commandResult->getData());
		}
		else
		{
			$addResult->addErrors($commandResult->getErrors());
		}

		return $addResult;
	}

	private function normalizeFields(TaskParams $taskParams, array $requestParams): array
	{
		if (array_key_exists(self::FIELD_AUTHOR, $requestParams))
		{
			if (is_scalar($requestParams[self::FIELD_AUTHOR]))
			{
				/** @compatibility '5afa6' => 5 from forum */
				/** @uncompatibility (int)[123] => 1 from forum */
				$requestParams[self::FIELD_AUTHOR] = (int)$requestParams[self::FIELD_AUTHOR];
			}
		}
		else
		{
			$requestParams[self::FIELD_AUTHOR] = $taskParams->userId;
		}

		return $requestParams;
	}

	private function validateFields(TaskParams $taskParams, array $requestParams): Result
	{
		$validateResult = new Result();

		$this->validateMessage($requestParams, $validateResult);
		$this->validateAuthor($taskParams, $requestParams, $validateResult);

		return $validateResult;
	}

	private function validateMessage(array $requestParams, Result $validateResult): void
	{
		$messageText = $requestParams[self::FIELD_MESSAGE] ?? null;

		if (!is_string($messageText) || empty($messageText))
		{
			$message = Loc::getMessage('TASK_REST_ADD_COMMENT_ERROR_MESSAGE_EMPTY');
			$validateResult->addError(new Error($message));
		}
	}

	private function validateAuthor(TaskParams $taskParams, array $requestParams, Result $validateResult): void
	{
		$authorId = $requestParams[self::FIELD_AUTHOR];

		if (!is_numeric($authorId) || $authorId < 1)
		{
			$message = Loc::getMessage('TASK_REST_ADD_COMMENT_ACCESS_DENIED');
			$validateResult->addError(new Error($message));

			return;
		}

		if (
			$authorId !== $taskParams->userId
			&& !$this->userRepository->isExists($authorId)
		)
		{
			$message = Loc::getMessage('TASK_REST_ADD_COMMENT_ACCESS_DENIED');
			$validateResult->addError(new Error($message));
		}
	}

	private function add(TaskParams $taskParams, array $requestParams): Result
	{
		$runResult = new Result();

		try
		{
			$command = $this->makeCommand($taskParams, $requestParams);
			$commandResult = $this->runCommand($command);

			if ($commandResult->isSuccess())
			{
				$runResult->setData($commandResult->getData());
			}
			// do not return chat error in result, only data if exists
		}
		catch (CommandValidationException $exception)
		{
			$this->remapValidationErrors($exception, $runResult);
		}
		catch (CommandException $exception)
		{
			$errorMessage = $exception->getPrevious()->getMessage();
			$runResult->addError(new Error($errorMessage));
		}

		return $runResult;
	}

	private function remapValidationErrors(CommandValidationException $exception, Result $runResult): void
	{
		$errors = $exception->getValidationErrors();
		$internalErrors = [];

		foreach ($errors as $error) {
			$errorFieldName = $error->getCode();
			$mappedError = $this->getErrorTextByHandlerField($errorFieldName);
			$internalErrors[$mappedError] = true;
		}

		foreach ($internalErrors as $errorMessage => $bool) {
			$runResult->addError(new Error($errorMessage));
		}
	}

	private function makeCommand(TaskParams $taskParams, array $requestParams): SendMessageCommand
	{
		$messageEntity = new Integration\Im\Entity\Message(
			text: (string)$requestParams[self::FIELD_MESSAGE],
		);

		return new SendMessageCommand(
			$taskParams->taskId,
			(int)$requestParams[self::FIELD_AUTHOR],
			$messageEntity,
		);
	}

	/**
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	protected function runCommand(SendMessageCommand $command): \Bitrix\Main\Result
	{
		return $command->run();
	}

	private function getErrorTextByHandlerField(string $fieldName): string
	{
		return match ($fieldName) {
			'taskId', 'userId' => Loc::getMessage('TASK_REST_ADD_COMMENT_ACCESS_DENIED'),
			'message.text' => Loc::getMessage('TASK_REST_ADD_COMMENT_ERROR_MESSAGE_EMPTY'),
			/** @compatibility CTaskItem::getInstance assertion */
			default => 'TASKS_ERROR_ASSERT_EXCEPTION',
		};
	}
}
