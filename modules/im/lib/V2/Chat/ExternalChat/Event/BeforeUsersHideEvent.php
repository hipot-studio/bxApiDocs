<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

class BeforeUsersHideEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(ExternalChat $chat, int $initiatorId, array $userIds)
	{
		$parameters = ['initiatorId' => $initiatorId, 'userIds' => $userIds];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'BeforeUsersHide';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
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
