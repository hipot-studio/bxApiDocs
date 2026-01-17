<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat;

class AfterMuteEvent extends ChatEvent
{
	public function __construct(ExternalChat $chat, bool $isMuted, int $userId)
	{
		$parameters = ['isMuted' => $isMuted, 'userId' => $userId];
		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'AfterMuteChat';
	}

	public function isMuted(): bool
	{
		return $this->parameters['isMuted'];
	}

	public function getUserId(): int
	{
		return $this->parameters['userId'];
	}
}
