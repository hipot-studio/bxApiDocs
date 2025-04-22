<?php

namespace Bitrix\Im\V2\Message\Delete\Strategy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Delete\DeletionMode;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;

abstract class DeletionStrategy
{
	protected MessageCollection $messages;
	protected ?Chat $chat;

	final protected function __construct(MessageCollection $messages)
	{
		$this->messages = $messages;
		$chatId = $messages->getCommonChatId();
		$this->chat = Chat::getInstance($chatId);
	}

	public static function getInstance(MessageCollection $messages, DeletionMode $deletionMode): DeletionStrategy
	{
		return match ($deletionMode)
		{
			DeletionMode::Soft => (new SoftDeletionStrategy($messages)),
			DeletionMode::Complete => (new CompleteDeletionStrategy($messages)),
			default => (new NoneDeletionStrategy($messages)),
		};
	}

	/**
	 * @throws InterruptedExecutionException
	 */
	abstract protected function onBeforeDelete(): void;

	/**
	 * @throws InterruptedExecutionException
	 */
	abstract protected function onAfterDelete(): void;

	/**
	 * @throws InterruptedExecutionException
	 */
	abstract protected function execute(): void;


	/**
	 * @throws InterruptedExecutionException
	 */
	protected function checkResult(Result $result): void
	{
		if (!$result->isSuccess())
		{
			throw new InterruptedExecutionException($result);
		}
	}

	final public function delete(): Result
	{
		$result = new Result();

		try
		{
			$this->onBeforeDelete();
			$this->execute();
			$this->onAfterDelete();
		}
		catch (InterruptedExecutionException $exception)
		{
			$result = $exception->getResult();
		}
		finally
		{
			return $result;
		}
	}
}