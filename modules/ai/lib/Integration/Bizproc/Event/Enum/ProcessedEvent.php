<?php

declare(strict_types=1);

namespace Bitrix\Ai\Integration\Bizproc\Event\Enum;

enum ProcessedEvent: string
{
	case OnAiAgentStart = 'OnAiAgentStart';
}
