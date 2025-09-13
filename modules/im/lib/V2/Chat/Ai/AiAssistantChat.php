<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Ai;

class AiAssistantChat extends AiAssistantBaseChat
{
	protected function getDefaultType(): string
	{
		return self::IM_TYPE_AI_ASSISTANT;
	}
}
