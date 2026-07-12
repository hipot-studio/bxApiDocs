<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Relation\DeleteUserConfig;

class AfterUsersDeleteEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, array $userIds, DeleteUserConfig $config)
	{
		$parameters = ['userIds' => $userIds, 'config' => $config];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterUsersDelete';
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}

	public function getDeleteUserConfig(): DeleteUserConfig
	{
		return $this->parameters['config'];
	}
}
