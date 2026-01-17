<?php

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\AI\Engine;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

class CopilotEvent extends Event
{
	protected function getTool(): string
	{
		return 'ai';
	}

	protected function getCategory(string $eventName): string
	{
		return 'chat_operations';
	}

	protected function setDefaultParams(?Engine $engine = null, ?string $promptCode = null): self
	{
		$this
			->setSection('copilot_tab')
			->setCopilotP2()
			->setCopilotP3()
			->setCopilotP4()
			->setCopilotP5()
		;

		return $this;
	}

	public function setCopilotP1(?string $promptCode): self
	{
		$this->p1 = isset($promptCode) ? ('1st-type_' . self::convertUnderscore($promptCode)) : 'none';

		return $this;
	}

	protected function setCopilotP2(): self
	{
		$engineName = null;
		if (Loader::includeModule('imbot'))
		{
			$engineName = CopilotChatBot::getEngineByChat($this->chat)?->getIEngine()?->getName();
		}

		$engineName ??= 'none';
		$this->p2 = 'provider_' . $engineName;

		return $this;
	}

	protected function setCopilotP3(): self
	{
		$this->p3 = $this->chat->getUserCount() > 2 ? 'chatType_multiuser' : 'chatType_private';

		return $this;
	}

	protected function setCopilotP4(): self
	{
		$role = (new RoleManager())->getMainRole($this->chat->getChatId()) ?? '';
		$this->p4 = 'role_' . self::convertUnderscore($role);

		return $this;
	}

	protected function setCopilotP5(): self
	{
		$this->p5 = 'chatId_' . $this->chat->getChatId();

		return $this;
	}
}
