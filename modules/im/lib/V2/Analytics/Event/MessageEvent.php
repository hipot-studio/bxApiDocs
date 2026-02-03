<?php

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Main\DI\ServiceLocator;

class MessageEvent extends ChatEvent
{
	public function setReactionP4(int $authorId): self
	{
		$aiAssistantService = ServiceLocator::getInstance()->get(AiAssistantService::class);
		if ($aiAssistantService->getBotId() > 0 && $aiAssistantService->getBotId() === $authorId)
		{
			$this->p4 = 'msgSender_aiAssistant';
		}

		return $this;
	}

	public function setReactionP3(int $reactionCount): self
	{
		$this->p3 = 'reactionCnt_' . $reactionCount;

		return $this;
	}
}
