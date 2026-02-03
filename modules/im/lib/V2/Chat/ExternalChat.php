<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterMuteEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDeleteMessagesEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\GetUsersForRecentEvent;
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

	protected function processUpdateStateOnRelationsChanged(RelationChangeSet $changes): Result
	{
		(new Chat\ExternalChat\Event\AfterUsersAddEvent($this, $changes))->send();

		return parent::processUpdateStateOnRelationsChanged($changes);
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

	public function onAfterAllMessagesRead(int $readerId): Result
	{
		(new AfterReadAllMessagesEvent($this, $readerId))->send();

		return parent::onAfterAllMessagesRead($readerId);
	}
}
