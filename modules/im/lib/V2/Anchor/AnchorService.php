<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Anchor;

use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Anchor\Push\PushService;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\SystemException;

final class AnchorService
{
	use ContextCustomer;

	private Message $message;
	private Chat $chat;
	private PushService $pushService;
	private AnchorProvider $anchorProvider;
	private ExceptionHandler $exceptionHandler;

	public function __construct(Message $message)
	{
		$this->message = $message;

		$this->init();
	}

	public function addMentionAnchor(array $userIds): Result
	{
		$anchorCollection = new AnchorCollection();

		$authorId = $this->message->getAuthorId();
		// Loads the relations of the whole list at once, so the check below reads them from the chat.
		$this->chat->getRelationsByUserIds($userIds);
		if ($this->chat instanceof Chat\CommentChat)
		{
			// The border of the post answers per reader as well, so the relations of the channel are read
			// in one go too.
			$this->chat->getParentChat()->getRelationsByUserIds($userIds);
		}

		foreach ($userIds as $userId)
		{
			if ($userId <= 0 || $authorId === $userId)
			{
				continue;
			}

			// An edit can mention someone in a message that is already behind their history border: the
			// anchor would keep a counter alive on a message they cannot open.
			if (!$this->isMessageAvailableForUser($userId))
			{
				continue;
			}

			$anchorItem = (new AnchorItem())
				->setChatId($this->message->getChatId())
				->setMessageId($this->message->getMessageId())
				->setUserId($userId)
				->setFromUserId($authorId)
				->setType(AnchorItem::MENTION)
			;

			$anchorCollection->add($anchorItem);
		}

		return $this->saveAnchors($anchorCollection);
	}

	public function addReactionAnchor(string $reaction, ?int $fromUserId = null): Result
	{
		$fromUserId ??= $this->getContext()->getUserId();
		if ($this->message->getAuthorId() === $fromUserId)
		{
			return new Result();
		}

		$authorId = $this->message->getAuthorId();
		if ($authorId <= 0)
		{
			return new Result();
		}

		if (!$this->isMessageAvailableForUser($authorId))
		{
			return new Result();
		}

		$anchorItem = (new AnchorItem())
			->setChatId($this->message->getChatId())
			->setMessageId($this->message->getMessageId())
			->setUserId($authorId)
			->setFromUserId($fromUserId)
			->setType(AnchorItem::REACTION)
			->setSubType($reaction)
		;

		return $this->saveAnchor($anchorItem);
	}

	public function deleteReactionAnchors(?int $fromUserId = null): Result
	{
		$fromUserId ??= $this->getContext()->getUserId();
		if ($this->message->getAuthorId() === $fromUserId)
		{
			return new Result();
		}

		return $this->deleteAnchors(
			type: AnchorItem::REACTION,
			fromUserId: $fromUserId,
			messageId: $this->message->getId()
		);
	}

	public function deleteMentionAnchors(?int $fromUserId = null): Result
	{
		$fromUserId ??= $this->getContext()->getUserId();

		return $this->deleteAnchors(
			type: AnchorItem::MENTION,
			fromUserId: $fromUserId,
			messageId: $this->message->getId()
		);
	}

	public function deleteUsersMentionAnchors(array $userIds): Result
	{
		if (empty($userIds))
		{
			return new Result();
		}

		return $this->deleteAnchors(
			type: AnchorItem::MENTION,
			fromUserId: $this->getContext()->getUserId(),
			userIds: $userIds,
			messageId: $this->message->getId(),
		);
	}

	private function deleteAnchors(
		?string $type = null,
		?int $fromUserId = null,
		?array $userIds = null,
		?int $messageId = null,
		?int $chatId = null,
	): Result
	{
		$result = new Result();

		$filter = [
			'TYPE' => $type,
			'FROM_USER_ID' => $fromUserId,
			'USER_ID' => $userIds,
			'MESSAGE_ID' => $messageId,
			'CHAT_ID' => $chatId,
		];

		try
		{
			$anchorCollection = AnchorCollection::find($filter);
			if ($anchorCollection->isEmpty())
			{
				return $result;
			}

			$deleteResult = $anchorCollection->delete();
			if (!$deleteResult->isSuccess())
			{
				return $result->addErrors($deleteResult->getErrors());
			}
		}
		catch (SystemException $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return $result->addError(new AnchorError(AnchorError::UNEXPECTED));
		}

		$this->anchorProvider->cleanUsersCache((array)$anchorCollection->getUserIdList());

		$this->pushService->deleteMulti($anchorCollection);

		return $result;
	}

	private function saveAnchors(AnchorCollection $anchorCollection): Result
	{
		$result = new Result();

		if ($anchorCollection->isEmpty())
		{
			return $result;
		}

		if (!$anchorCollection->getCommonMessageId())
		{
			return $result->addError(new AnchorError(AnchorError::UNCOMMON_MESSAGE));
		}

		try
		{
			$saveResult = $anchorCollection->save(true);
			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}
		}
		catch (SystemException $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return $result->addError(new AnchorError(AnchorError::UNEXPECTED));
		}

		$chat = $this->message->getChat();
		$anchorCollection->setParentChatId($chat->getParentChatId());
		$anchorCollection->setParentMessageId($chat->getParentMessageId());
		$anchorCollection->setRecentSections($chat->getRecentSections());

		$this->anchorProvider->cleanUsersCache((array)$anchorCollection->getUserIdList());

		$this->pushService->addMulti($anchorCollection);

		return $result;
	}

	private function saveAnchor(AnchorItem $anchorItem): Result
	{
		$result = new Result();

		try
		{
			$saveResult = $anchorItem->save();
			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}
		}
		catch (DuplicateEntryException)
		{
			return $result;
		}
		catch (SystemException $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return $result->addError(new AnchorError(AnchorError::UNEXPECTED));
		}

		$chat = $this->message->getChat();
		$anchorItem->setParentChatId($chat->getParentChatId());
		$anchorItem->setParentMessageId($chat->getParentMessageId());
		$anchorItem->setRecentSections($chat->getRecentSections());

		$this->anchorProvider->cleanCache($anchorItem->getUserId());

		$this->pushService->add($anchorItem);

		return $result;
	}

	private function isMessageAvailableForUser(int $userId): bool
	{
		// An anchor is a personal pointer: the counter and the recent row it feeds hang on the relation of
		// its reader, so a reader without one gets none.
		if ($this->chat->getRelationByUserId($userId) === null)
		{
			return false;
		}

		return !$this->chat->isMessageBehindUserHistoryBorder(
			(int)$this->message->getMessageId(),
			$userId
		);
	}

	private function init(): void
	{
		$this->chat = Chat::getInstance($this->message->getChatId());
		$this->pushService = AnchorContainer::getInstance()->getPushService();
		$this->anchorProvider = AnchorContainer::getInstance()->getAnchorProvider();
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}
}