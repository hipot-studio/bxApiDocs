<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

class AfterUsersHideEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, array $userIds)
	{
		$parameters = ['userIds' => $userIds];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterUsersHide';
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}
}
