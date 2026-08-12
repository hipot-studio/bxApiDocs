<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

final class AutoExecuteFilter
{
	public static function getFilterValue(int $eventType): int|array
	{
		return match ($eventType)
		{
			\CBPDocumentEventType::None => 0,
			\CBPDocumentEventType::Create => [1, 3, 5, 7],
			\CBPDocumentEventType::Edit => [2, 3, 6, 7],
			\CBPDocumentEventType::Delete => [4, 5, 6, 7],
			\CBPDocumentEventType::Automation => 8,
			\CBPDocumentEventType::Script => 32,
			default => [-1],
		};
	}
}
