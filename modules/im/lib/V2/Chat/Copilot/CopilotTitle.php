<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Pull\Event\SetCopilotTitle;

class CopilotTitle
{
	protected int $chatId;
	protected Params $params;

	public function __construct(int $chatId)
	{
		$this->chatId = $chatId;
		$this->params = Params::getInstance($chatId);
	}

	public function isCustom(): bool
	{
		return (bool)($this->params->get(Params::COPILOT_TITLE_IS_CUSTOM)?->getValue());
	}

	public function markAsCustom(): void
	{
		if (!$this->chatId)
		{
			return;
		}

		$chat = Chat::getInstance($this->chatId);
		Chat\CopilotChat::activateDraftIfNeeded($chat);

		if ($this->isCustom())
		{
			return;
		}

		$this->params->addParamByName(Params::COPILOT_TITLE_IS_CUSTOM, true);

		$this->sendPush($chat);
	}

	protected function sendPush(Chat $chat): void
	{
		(new SetCopilotTitle($chat))->send();
	}
}
