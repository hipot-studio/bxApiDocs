<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

class BeforeUsersHideEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, array $userIds)
	{
		$parameters = ['userIds' => $userIds];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'BeforeUsersHide';
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}

	public function getNewUserIds(): ?array
	{
		return $this->getParameterFromResult('userIds');
	}
}
