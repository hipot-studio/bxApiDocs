<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\RelationChangeSet;

class AfterUsersAddEvent extends ChatEvent implements InitiatedByUserInterface
{
	public function __construct(
		ExternalChat $chat,
		int $initiatorId,
		RelationChangeSet $changes,
		?AddUsersConfig $config = null
	)
	{
		$parameters = [
			'initiatorId' => $initiatorId,
			'changes' => $changes,
			'addUsersConfig' => $config,
		];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterUsersAdd';
	}

	public function getInitiatorId(): int
	{
		return $this->parameters['initiatorId'];
	}

	public function getChanges(): RelationChangeSet
	{
		return $this->parameters['changes'];
	}

	public function getAddUsersConfig(): ?AddUsersConfig
	{
		return $this->parameters['addUsersConfig'] ?? null;
	}
}