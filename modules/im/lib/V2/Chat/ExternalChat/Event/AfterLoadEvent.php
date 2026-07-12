<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

final class AfterLoadEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, int $userId)
	{
		parent::__construct($chat, ['userId' => $userId]);
	}

	protected function getActionName(): string
	{
		return 'AfterLoad';
	}

	public function getUserId(): int
	{
		return (int)$this->parameters['userId'];
	}
}
