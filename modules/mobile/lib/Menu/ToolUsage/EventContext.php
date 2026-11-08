<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\ToolUsage;

/**
 * Tourist keeps the payload of an event as a JSON string, so every reader of tool usage events decodes
 * it the same way: a missing, empty or non-array context is not an error but "nothing was stored".
 */
final class EventContext
{
	public static function decode(?array $eventData): ?array
	{
		$contextRaw = $eventData['context'] ?? null;
		if (!is_string($contextRaw) || $contextRaw === '')
		{
			return null;
		}

		$decoded = json_decode($contextRaw, true);

		return is_array($decoded) ? $decoded : null;
	}
}
