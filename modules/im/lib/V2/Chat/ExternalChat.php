<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeCreateEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\GetUsersForRecentEvent;
use Bitrix\Im\V2\Chat\ExternalChat\ExternalTypeRegistry;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\ExternalChatRelations;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Context;

class ExternalChat extends GroupChat
{
	protected Config $config;

	public function add(array $params, ?Context $context = null): Result
	{
		$beforeCreateEvent = new BeforeCreateEvent($params);
		$beforeCreateEvent->send();
		$result = $beforeCreateEvent->getResult();

		if (!$result->isSuccess())
		{
			return $result;
		}

		$params = $result->getResult()['fields'] ?? $params;

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
		if (!$event->getResult()->isSuccess())
		{
			return $this;
		}

		$userIds = $event->getNewUserIds() ?? $userIds;
		$config = $event->getNewAddUsersConfig() ?? $config;

		return parent::addUsers($userIds, $config);
	}

	public function getConfig(): Config
	{
		$this->config ??=
			ExternalTypeRegistry::getInstance()->getConfigByType($this->getExtendedType(false))
			?? new Config()
		;

		return $this->config;
	}

	protected function needToSendMessageUserDelete(): bool
	{
		return false;
	}
}
