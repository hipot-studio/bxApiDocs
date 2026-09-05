<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Relation\DeleteUserConfig;

class BeforeUsersDeleteEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(ExternalChat $chat, int $initiatorId, array $userIds, DeleteUserConfig $config)
	{
		$parameters = ['initiatorId' => $initiatorId, 'userIds' => $userIds, 'config' => $config];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'BeforeUsersDelete';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}

	public function getDeleteUserConfig(): DeleteUserConfig
	{
		return $this->parameters['config'];
	}

	public function getNewDeleteUserConfig(): ?DeleteUserConfig
	{
		return $this->getParameterFromResult('config');
	}

	public function getNewUserIds(): ?array
	{
		return $this->getParameterFromResult('userIds');
	}
}