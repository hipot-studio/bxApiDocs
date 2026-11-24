<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled;

use Bitrix\Im\Message\Uuid;
use Bitrix\Im\Model\ScheduledMessageTable;
use Bitrix\Im\V2\Analytics\ScheduledMessageAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Scheduled\Async\ScheduledMessageCleanupMessage;
use Bitrix\Im\V2\Message\Scheduled\Error\ScheduledMessageCleanupException;
use Bitrix\Im\V2\Message\Scheduled\Pull\ScheduledMessageCreate;
use Bitrix\Im\V2\Message\Scheduled\Pull\ScheduledMessageDelete;
use Bitrix\Im\V2\Message\Scheduled\Pull\ScheduledMessagesStatusUpdate;
use Bitrix\Im\V2\Message\Scheduled\Pull\ScheduledMessageUpdate;
use Bitrix\Im\V2\Message\Scheduled\Update\UpdateFields;
use Bitrix\Im\V2\Message\Scheduled\Update\UpdateResult;
use Bitrix\Im\V2\Message\Scheduled\Async\ScheduledMessageSendMessage;
use Bitrix\Im\V2\Message\Scheduled\Create\CreateResult;
use Bitrix\Im\V2\Message\Scheduled\Create\ScheduledMessageFields;
use Bitrix\Im\V2\Message\Scheduled\Delete\DeleteResult;
use Bitrix\Im\V2\Message\Scheduled\Error\ScheduledMessageError;
use Bitrix\Im\V2\Message\Scheduled\GetList\GetListResult;
use Bitrix\Im\V2\Message\Scheduled\GetList\ScheduledMessageCursor;
use Bitrix\Im\V2\Message\Scheduled\SendNow\SendNowResult;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendResult;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\ValidationService;
use Throwable;

class ScheduledMessageService
{
	public const MAX_SCHEDULED_PER_CHAT = 1000;
	public const MIN_DELAY_SECONDS = 0;
	public const MAX_DELAY_SECONDS = 365 * 24 * 60 * 60;
	public const SEND_WINDOW_SECONDS = 60 * 60;
	public const RETRY_INTERVAL_SECONDS = 5 * 60;
	public const DEFAULT_LIST_LIMIT = 50;
	public const MAX_LIST_LIMIT = 200;
	public const MARK_EXPIRED_AS_FAILED_BATCH_LIMIT = 500;

	public function __construct(
		private readonly ScheduledMessageProvider $scheduledMessageProvider,
		private readonly ValidationService $validationService
	)
	{
	}

	public function create(ScheduledMessageFields $fields, int $userId): CreateResult
	{
		$result = new CreateResult();

		$user = User::getInstance($userId);
		$chat = Chat::getInstance($fields->chatId);

		$analytics = (new ScheduledMessageAnalytics($chat))->withContextUser($user);

		$validation = $this->validationService->validate($fields);
		if (!$validation->isSuccess())
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_INVALID_FIELDS);

			return $result->addErrors($validation->getErrors());
		}

		if (!Uuid::validate($fields->uuid))
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_INVALID_UUID);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::INVALID_UUID));
		}

		if ($this->isSendAtOutOfRange($fields->sendAt))
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_SEND_AT_OUT_OF_RANGE);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::SEND_AT_OUT_OF_RANGE));
		}

		if (!$user->isExist() || !$user->isActive())
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_USER_DEACTIVATED);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		if (!$chat->isExist())
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_CHAT_DELETED);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::CHAT_NOT_FOUND));
		}

		if (!$chat->withContextUser($user)->canDo(Action::Send))
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_NO_PERMISSION);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		$existingScheduledMessage = $this->scheduledMessageProvider->getByUuidAndAuthorId($fields->uuid, $userId);
		if ($existingScheduledMessage !== null)
		{
			return $result->setScheduledMessage($existingScheduledMessage);
		}

		$scheduledCountInChat = $this->scheduledMessageProvider->getScheduledCountByChatId($chat->getId());
		if ($scheduledCountInChat >= self::MAX_SCHEDULED_PER_CHAT)
		{
			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_PER_CHAT_LIMIT_EXCEEDED);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::PER_CHAT_LIMIT_EXCEEDED));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$createdScheduledMessage = new ScheduledMessage();
			$createdScheduledMessage
				->setChatId($fields->chatId)
				->setAuthorId($userId)
				->setUuid($fields->uuid)
				->setMessage($fields->message)
				->setSendAt($fields->sendAt)
			;

			$saveResult = $createdScheduledMessage->save();

			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();

				$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_CREATE_FAILED);

				return $result->addErrors($saveResult->getErrors());
			}

			$message = new ScheduledMessageSendMessage($createdScheduledMessage->getId());
			$message->sendToQueueAt($createdScheduledMessage->getSendAt());

			$connection->commitTransaction();
		}
		catch (DuplicateEntryException $exception)
		{
			$connection->rollbackTransaction();

			$existingScheduledMessage = $this->scheduledMessageProvider->getByUuidAndAuthorId($fields->uuid, $userId);
			if ($existingScheduledMessage !== null)
			{
				return $result->setScheduledMessage($existingScheduledMessage);
			}

			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_CREATE_FAILED);

			throw $exception;
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			$analytics->addCreate(status: ScheduledMessageAnalytics::STATUS_ERROR_CREATE_FAILED);

			throw $exception;
		}

		(new ScheduledMessageCreate($createdScheduledMessage, $userId))->send();

		$delay = (new DateTime())->getDiff($createdScheduledMessage->getSendAt());
		$analytics->addCreate($delay);

		return $result->setScheduledMessage($createdScheduledMessage);
	}

	public function sendByScheduler(int $id): void
	{
		$scheduledMessage = $this->scheduledMessageProvider->getById($id);

		if ($scheduledMessage === null)
		{
			return;
		}

		if ($scheduledMessage->getStatus() !== ScheduledMessageStatus::Scheduled->value)
		{
			return;
		}

		$now = new DateTime();
		if ($scheduledMessage->getSendAt() > $now)
		{
			return;
		}

		$author = User::getInstance($scheduledMessage->getAuthorId());
		$chat = Chat::getInstance($scheduledMessage->getChatId());

		$analytics = (new ScheduledMessageAnalytics($chat))->withContextUser($author);

		$sendWindow = clone $scheduledMessage->getSendAt();
		$sendWindow = $sendWindow->add(self::SEND_WINDOW_SECONDS . 'seconds');
		if ($now > $sendWindow)
		{
			$scheduledMessage->markFailed();
			$saveResult = $scheduledMessage->save();

			if (!$saveResult->isSuccess())
			{
				throw new RecoverableMessageException(retryDelay: $this->getNextRetryDelay($id, $now->getTimestamp()));
			}

			(new ScheduledMessageUpdate($scheduledMessage, $scheduledMessage->getAuthorId()))->send();

			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_SENDING_WINDOW_PASSED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_SCHEDULED
			);

			return;
		}

		if (!$author->isExist() || !$author->isActive())
		{
			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_USER_DEACTIVATED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_SCHEDULED
			);

			return;
		}

		if (!$chat->isExist())
		{
			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_CHAT_DELETED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_SCHEDULED
			);

			return;
		}

		if (!$chat->withContextUser($author)->canDo(Action::Send))
		{
			$scheduledMessage->markFailed();
			$saveResult = $scheduledMessage->save();

			if (!$saveResult->isSuccess())
			{
				throw new RecoverableMessageException(retryDelay: $this->getNextRetryDelay($id, $now->getTimestamp()));
			}

			(new ScheduledMessageUpdate($scheduledMessage, $scheduledMessage->getAuthorId()))->send();

			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_NO_PERMISSION,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_SCHEDULED
			);

			return;
		}

		$sendResult = $this->sendMessage(
			$scheduledMessage,
			$author,
			$chat,
			ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_SCHEDULED
		);

		if (!$sendResult->isSuccess())
		{
			throw new RecoverableMessageException(retryDelay: $this->getNextRetryDelay($id, $now->getTimestamp()));
		}

		$deleteResult = $this->delete($scheduledMessage);
		if (!$deleteResult->isSuccess())
		{
			$message = new ScheduledMessageCleanupMessage($id);
			$message->sendToQueue();
		}
	}

	private function getNextRetryDelay(int $scheduledMessageId, int $now): int
	{
		$offset = $scheduledMessageId % self::RETRY_INTERVAL_SECONDS;

		$nextRetryAt = $now - ($now % self::RETRY_INTERVAL_SECONDS) + $offset;
		$nextRetryAt = $nextRetryAt <= $now ? $nextRetryAt + self::RETRY_INTERVAL_SECONDS : $nextRetryAt;

		return $nextRetryAt - $now;
	}

	public function sendNow(ScheduledMessage $scheduledMessage): SendNowResult
	{
		$result = new SendNowResult();

		$author = User::getInstance($scheduledMessage->getAuthorId());
		$chat = Chat::getInstance($scheduledMessage->getChatId());

		$analytics = (new ScheduledMessageAnalytics($chat))->withContextUser($author);

		if (!$author->isExist() || !$author->isActive())
		{
			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_USER_DEACTIVATED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_MANUAL
			);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		if (!$chat->isExist())
		{
			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_CHAT_DELETED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_MANUAL
			);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::CHAT_NOT_FOUND));
		}

		if (!$chat->withContextUser($author)->canDo(Action::Send))
		{
			$scheduledMessage->markFailed();
			$saveResult = $scheduledMessage->save();

			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}

			(new ScheduledMessageUpdate($scheduledMessage, $scheduledMessage->getAuthorId()))->send();

			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_NO_PERMISSION,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_MANUAL
			);

			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		$sendResult = $this->sendMessage(
			$scheduledMessage,
			$author,
			$chat,
			ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_MANUAL
		);

		if (!$sendResult->isSuccess())
		{
			$analytics->addSend(
				$scheduledMessage,
				ScheduledMessageAnalytics::STATUS_ERROR_SEND_FAILED,
				ScheduledMessageAnalytics::SEND_MESSAGE_TYPE_MANUAL
			);

			return $result->addErrors($sendResult->getErrors());
		}

		$scheduledMessageId = $scheduledMessage->getId();
		$deleteResult = $this->delete($scheduledMessage);
		if (!$deleteResult->isSuccess())
		{
			$message = new ScheduledMessageCleanupMessage($scheduledMessageId);
			$message->sendToQueue();
		}

		return $result->setMessageId($sendResult->getMessageId());
	}

	private function sendMessage(
		ScheduledMessage $scheduledMessage,
		User $fromUser,
		Chat $toChat,
		string $sendMessageAnalyticsType
	): SendResult
	{
		$message =
			(new Message())
				->setAuthorId($fromUser->getId())
				->setMessage($scheduledMessage->getMessage())
				->setUuid($scheduledMessage->getUuid())
		;

		$sendingConfig = (new SendingConfig())->setSendMessageAnalyticsType($sendMessageAnalyticsType);

		$sendResult =
			$toChat
				->withContextUser($fromUser)
				->sendMessage($message, $sendingConfig)
		;

		if ($sendResult->isSuccess())
		{
			$sendResult->getPromise()?->wait();
		}

		return $sendResult;
	}

	public function deleteManually(ScheduledMessage $scheduledMessage): DeleteResult
	{
		$result = $this->delete($scheduledMessage);

		$user = User::getInstance($scheduledMessage->getAuthorId());
		$chat = Chat::getInstance($scheduledMessage->getChatId());
		$analytics = (new ScheduledMessageAnalytics($chat))->withContextUser($user);

		$analytics->addDelete(
			$result->isSuccess()
				? ScheduledMessageAnalytics::STATUS_SUCCESS
				: ScheduledMessageAnalytics::STATUS_ERROR_DELETE_FAILED
		);

		return $result;
	}

	public function delete(ScheduledMessage $scheduledMessage): DeleteResult
	{
		$result = new DeleteResult();

		$scheduledMessageId = $scheduledMessage->getId();
		$authorId = $scheduledMessage->getAuthorId();

		$deleteResult = $scheduledMessage->delete();
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		(new ScheduledMessageDelete($scheduledMessageId, $authorId))->send();

		return $result->setScheduledMessageId($scheduledMessageId);
	}

	public function deleteByChatId(int $chatId): void
	{
		ScheduledMessageTable::deleteByFilter([
			'=CHAT_ID' => $chatId,
		]);
	}

	public function deleteByAuthorId(int $authorId): void
	{
		ScheduledMessageTable::deleteByFilter([
			'=AUTHOR_ID' => $authorId,
		]);
	}

	/**
	 * @param int[] $authorIds
	 */
	public function deleteByChatIdAndAuthorIds(int $chatId, array $authorIds): void
	{
		if ($authorIds === [])
		{
			return;
		}

		ScheduledMessageTable::deleteByFilter([
			'=CHAT_ID' => $chatId,
			'@AUTHOR_ID' => $authorIds,
		]);
	}

	public function cleanupSentScheduledMessage(int $id): void
	{
		$scheduledMessage = $this->scheduledMessageProvider->getById($id);

		if ($scheduledMessage === null)
		{
			return;
		}

		$deleteResult = $this->delete($scheduledMessage);
		if (!$deleteResult->isSuccess())
		{
			throw new ScheduledMessageCleanupException('Unable to delete sent scheduled message.');
		}
	}

	public function getList(
		int $userId,
		int $chatId,
		int $limit = self::DEFAULT_LIST_LIMIT,
		?ScheduledMessageCursor $lastCursor = null,
	): GetListResult
	{
		$result = new GetListResult();

		$author = User::getInstance($userId);
		if (!$author->isExist() || !$author->isActive())
		{
			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			return $result->addError(new ScheduledMessageError(ScheduledMessageError::CHAT_NOT_FOUND));
		}

		$accessResult = $chat->withContextUser($author)->checkAccess();
		if (!$accessResult->isSuccess())
		{
			return $result->addErrors($accessResult->getErrors());
		}

		$limit = $limit > 0 && $limit <= self::MAX_LIST_LIMIT ? $limit : self::DEFAULT_LIST_LIMIT;

		$scheduledMessages = $this->scheduledMessageProvider->getList(
			$userId,
			$chatId,
			$limit + 1,
			$lastCursor
		);

		if (count($scheduledMessages) > $limit)
		{
			array_pop($scheduledMessages);

			$lastMessage = $scheduledMessages[count($scheduledMessages) - 1];
			$lastMessageCursor = new ScheduledMessageCursor($lastMessage->getSendAt(), $lastMessage->getId());

			$result->setLastCursor($lastMessageCursor);
		}

		$result->setScheduledMessages($scheduledMessages);

		return $result;
	}

	public function update(ScheduledMessage $scheduledMessage, UpdateFields $fields): UpdateResult
	{
		$result = new UpdateResult();

		$validation = $this->validationService->validate($fields);
		if (!$validation->isSuccess())
		{
			return $result->addErrors($validation->getErrors());
		}

		if (isset($fields->message))
		{
			$scheduledMessage->setMessage($fields->message);

			$saveResult = $scheduledMessage->save();
			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}

			(new ScheduledMessageUpdate($scheduledMessage, $scheduledMessage->getAuthorId()))->send();
		}

		return $result->setScheduledMessage($scheduledMessage);
	}

	public function reschedule(ScheduledMessage $scheduledMessage, DateTime $sendAt): UpdateResult
	{
		$result = new UpdateResult();

		if ($this->isSendAtOutOfRange($sendAt))
		{
			return $result->addError(new ScheduledMessageError(ScheduledMessageError::SEND_AT_OUT_OF_RANGE));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$scheduledMessage->setSendAt($sendAt);
			$scheduledMessage->markScheduled();

			$saveResult = $scheduledMessage->save();
			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();
				return $result->addErrors($saveResult->getErrors());
			}

			$message = new ScheduledMessageSendMessage($scheduledMessage->getId());
			$message->sendToQueueAt($scheduledMessage->getSendAt());

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}

		(new ScheduledMessageUpdate($scheduledMessage, $scheduledMessage->getAuthorId()))->send();

		return $result->setScheduledMessage($scheduledMessage);
	}

	private function isSendAtOutOfRange(DateTime $sendAt): bool
	{
		$minSendAt = (new DateTime())->add(self::MIN_DELAY_SECONDS . 'seconds');
		$maxSendAt = (new DateTime())->add(self::MAX_DELAY_SECONDS . 'seconds');

		return $sendAt < $minSendAt || $sendAt > $maxSendAt;
	}

	public function markExpiredAsFailed(): void
	{
		$expiredAt = (new DateTime())->add('-' . self::SEND_WINDOW_SECONDS . 'seconds');

		$expiredIds = $this->scheduledMessageProvider
			->getExpiredIds($expiredAt, self::MARK_EXPIRED_AS_FAILED_BATCH_LIMIT)
		;

		if (!$expiredIds)
		{
			return;
		}

		ScheduledMessageTable::updateByFilter(
			[
				'=ID' => $expiredIds,
				'=STATUS' => ScheduledMessageStatus::Scheduled->value,
				'<SEND_AT' => $expiredAt,
			],
			[
				'STATUS' => ScheduledMessageStatus::Failed->value,
			],
		);

		$markedFailedScheduledMessages = $this->scheduledMessageProvider->getFailedByIds($expiredIds);

		$scheduledMessagesByAuthor = [];

		foreach ($markedFailedScheduledMessages as $scheduledMessage)
		{
			$scheduledMessagesByAuthor[$scheduledMessage->getAuthorId()][] = $scheduledMessage;
		}

		foreach ($scheduledMessagesByAuthor as $authorId => $scheduledMessages)
		{
			(new ScheduledMessagesStatusUpdate($scheduledMessages, $authorId))->send();
		}
	}

	public static function markExpiredAsFailedAgent(): string
	{
		$service = ServiceLocator::getInstance()->get(ScheduledMessageService::class);
		$service->markExpiredAsFailed();

		return __METHOD__ . '();';
	}
}
