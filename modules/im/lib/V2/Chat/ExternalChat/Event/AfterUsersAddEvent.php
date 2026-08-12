<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\RelationChangeSet;

class AfterUsersAddEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, RelationChangeSet $changes, ?AddUsersConfig $config = null)
	{
		$parameters = [
			'changes' => $changes,
			'addUsersConfig' => $config,
		];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterUsersAdd';
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