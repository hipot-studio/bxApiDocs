<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Relation\AddUsersConfig;

class BeforeUsersAddEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(ExternalChat $chat, int $initiatorId, array $userIds, AddUsersConfig $config)
	{
		$parameters = ['initiatorId' => $initiatorId, 'userIds' => $userIds, 'config' => $config];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'BeforeUsersAdd';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}

	public function getAddUsersConfig(): AddUsersConfig
	{
		return $this->parameters['config'];
	}

	public function getNewUserIds(): ?array
	{
		return $this->getParameterFromResult('userIds');
	}

	public function getNewAddUsersConfig(): ?AddUsersConfig
	{
		return $this->getParameterFromResult('config');
	}
}
