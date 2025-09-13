<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Ai;

class AiAssistantEntityChat extends AiAssistantBaseChat
{
	protected function getDefaultType(): string
	{
		return self::IM_TYPE_AI_ASSISTANT_ENTITY;
	}
}
