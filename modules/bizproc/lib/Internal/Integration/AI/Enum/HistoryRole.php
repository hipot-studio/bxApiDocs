<?php

namespace Bitrix\Bizproc\Internal\Integration\AI\Enum;

enum HistoryRole: string
{
	case Assistant = 'assistant';
	case User = 'user';
}