<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterAttachChildEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDetachChildEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterLoadEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeAttachChildEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeDetachChildEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterMuteEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersDeleteEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersHideEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersDeleteEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersHideEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDeleteMessagesEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\GetUsersForRecentEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\ResolveRecentFixedChatIdsEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadAllMessagesEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadMessagesEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUpdateMessageEvent;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalError;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Counter\CounterType;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendingService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\Relation\ExternalChatRelations;
use Bitrix\Im\V2\Relation\RelationChangeSet;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Delete\DeletionMode;
use Bitrix\Im\V2\Chat\Add\AddResult;
use Bitrix\Main\DI\ServiceLocator;

class ExternalChat extends GroupChat
{
	protected Config $config;

	private static array $loadedChatIds = [];

	public function add(array $params, ?Context $context = null): AddResult
	{
		$beforeCreateEvent = new BeforeCreateEvent($params);
		$beforeCreateEvent->send();

		if ($beforeCreateEvent->isCancelled())
		{
			return (new AddResult())->addError(new ExternalError(ExternalError::FROM_EVENT));
		}

		$params = $beforeCreateEvent->getNewFields() ?? $params;

		$addResult = parent::add($params, $context);

		(new AfterCreateEvent($params['ENTITY_TYPE'] ?? '', $addResult))->send();

		return $addResult;
	}

	protected function prepareParams(array $params = []): Result
	{
		if (empty($params['ENTITY_TYPE']))
		{
			return (new Result())->addError(new ChatError(ChatError::ENTITY_TYPE_EMPTY));
		}

		return parent::prepareParams($params);
	}

	protected function checkAccessInternal(int $userId): Result
	{
		$event = new FilterUsersByAccessEvent($this, [$userId]);
		$event->send();
		if (!$event->hasResult())
		{
			return parent::checkAccessInternal($userId);
		}

		$result = new Result();
		$error = $event->getError();
		if ($error !== null)
		{
			return $result->addError($error);
		}

		$usersWithAccess = $event->getUsersWithAccess();
		if (!in_array($userId, $usersWithAccess, true))
		{
			return $result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		return $result;
	}

	protected function getUsersToAddToRecent(): array
	{
		$event = new GetUsersForRecentEvent($this);
		$event->send();
		if (!$event->hasResult())
		{
			return parent::getUsersToAddToRecent();
		}

		return $event->getUsersForRecent();
	}

	/**
	 * @return int[]
	 */
	public function getRecentFixedChatIds(?string $recentSection, int $userId): array
	{
		$event = new ResolveRecentFixedChatIdsEvent($this, $recentSection, $userId);
		$event->send();

		return $event->getRecentFixedChatIds();
	}

	public function getRelationFacade(): ?ExternalChatRelations
	{
		if ($this->getId())
		{
			$this->chatRelations ??= ExternalChatRelations::getInstance($this->getId());
		}

		return $this->chatRelations;
	}

	public function isAutoJoinEnabled(): bool
	{
		return $this->getConfig()->isAutoJoinEnabled;
	}

	public function addUsers(array $userIds, AddUsersConfig $config = new AddUsersConfig()): Chat
	{
		$event = new BeforeUsersAddEvent($this, $userIds, $config);
		$event->send();
		if ($event->isCancelled())
		{
			return $this;
		}

		$userIds = $event->getNewUserIds() ?? $userIds;
		$config = $event->getNewAddUsersConfig() ?? $config;

		return parent::addUsers($userIds, $config);
	}

	protected function processUpdateStateOnRelationsChanged(RelationChangeSet $changes, ?AddUsersConfig $config = null): Result
	{
		(new Chat\ExternalChat\Event\AfterUsersAddEvent($this, $changes, $config))->send();

		return parent::processUpdateStateOnRelationsChanged($changes, $config);
	}

	public function deleteUser(int $userId, DeleteUserConfig $config = new DeleteUserConfig()): Result
	{
		$event = new BeforeUsersDeleteEvent($this, [$userId], $config);
		$event->send();

		if ($event->isCancelled())
		{
			return new Result();
		}

		$allowedUserIds = $event->getNewUserIds() ?? [$userId];
		$config = $event->getNewDeleteUserConfig() ?? $config;

		if (!in_array($userId, $allowedUserIds, true))
		{
			return new Result();
		}

		$result = parent::deleteUser($userId, $config);

		if ($result->isSuccess())
		{
			(new AfterUsersDeleteEvent($this, [$userId], $config))->send();
		}

		return $result;
	}

	public function hideUser(int $userId): Result
	{
		$event = new BeforeUsersHideEvent($this, [$userId]);
		$event->send();

		if ($event->isCancelled())
		{
			return new Result();
		}

		$allowedUserIds = $event->getNewUserIds() ?? [$userId];

		if (!in_array($userId, $allowedUserIds, true))
		{
			return new Result();
		}

		$result = parent::hideUser($userId);

		if ($result->isSuccess())
		{
			(new AfterUsersHideEvent($this, [$userId]))->send();
		}

		return $result;
	}

	public function getConfig(): Config
	{
		if (isset($this->config))
		{
			return $this->config;
		}

		$registry = ServiceLocator::getInstance()->get(ExternalTypeRegistry::class);
		$this->config =
			$registry->getConfigByExtendedType($this->getExtendedType(false))
			?? new Config()
		;

		return $this->config;
	}

	protected function needToSendMessageUserDelete(): bool
	{
		return false;
	}

	protected function onAfterMute(bool $isMuted, int $userId): Result
	{
		(new AfterMuteEvent($this, $isMuted, $userId))->send();

		return parent::onAfterMute($isMuted, $userId);
	}

	protected function onBeforeMessageSend(Message $message, SendingConfig $config): Result
	{
		(new Chat\ExternalChat\Event\BeforeMessageSendEvent($this, $message))->send();

		return parent::onBeforeMessageSend($message, $config);
	}

	protected function onAfterMessageSend(Message $message, SendingService $sendingService): void
	{
		(new AfterSendMessageEvent($this, $message))->send();

		parent::onAfterMessageSend($message, $sendingService);
	}

	public function onAfterMessageUpdate(Message $message): Result
	{
		(new AfterUpdateMessageEvent($this, $message))->send();

		return parent::onAfterMessageUpdate($message);
	}

	public function onAfterMessagesDelete(MessageCollection $messages, DeletionMode $deletionMode): Result
	{
		(new AfterDeleteMessagesEvent($this, $messages, $deletionMode))->send();

		return parent::onAfterMessagesDelete($messages, $deletionMode);
	}

	public function onAfterMessagesRead(MessageCollection $messages, int $readerId): Result
	{
		(new AfterReadMessagesEvent($this, $messages, $readerId))->send();

		return parent::onAfterMessagesRead($messages, $readerId);
	}

	public function onAfterLoad(int $userId): void
	{
		$chatId = $this->getChatId();
		if ($chatId <= 0 || isset(self::$loadedChatIds[$chatId]))
		{
			return;
		}
		self::$loadedChatIds[$chatId] = true;

		(new AfterLoadEvent($this, $userId))->send();
	}

	public function onBeforeAttachChild(GroupChat $childChat, int $userId): Result
	{
		$event = new BeforeAttachChildEvent($this, $childChat, $userId);
		$event->send();
		if ($event->isCancelled())
		{
			return (new Result())->addError(new ChatError(ChatError::FROM_OTHER_MODULE));
		}

		return new Result();
	}

	public function onAfterAttachChild(GroupChat $childChat, int $userId): void
	{
		(new AfterAttachChildEvent($this, $childChat, $userId))->send();
	}

	public function onBeforeDetachChild(GroupChat $childChat, int $userId): Result
	{
		$event = new BeforeDetachChildEvent($this, $childChat, $userId);
		$event->send();
		if ($event->isCancelled())
		{
			return (new Result())->addError(new ChatError(ChatError::FROM_OTHER_MODULE));
		}

		return new Result();
	}

	public function onAfterDetachChild(GroupChat $childChat, int $userId): void
	{
		(new AfterDetachChildEvent($this, $childChat, $userId))->send();
	}

	public function onAfterAllMessagesRead(int $readerId): Result
	{
		// Snapshot the read boundary (chat's last message id at read-all time) so the
		// deferred IM->Feed sync marks only posts whose system message existed now, not
		// ones cross-posted into the gap before the background job runs.
		$lastMessageId = (int)$this->getLastMessageId();
		(new AfterReadAllMessagesEvent($this, $readerId, $lastMessageId))->send();

		return parent::onAfterAllMessagesRead($readerId);
	}
}
