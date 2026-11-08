<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\Main\Event;

abstract class QueueJob
{
	protected Event $event;

	public function __construct(Event $event)
	{
		$this->event = $event;
	}

	public abstract function processQueueJob(): void;
	public abstract function processFailedJob(): void;

	/**
	 * Normalizes a list of ids from the AI context parameters: positive integers only,
	 * reindexed. The dedup entity hash is built from this list, so every job must
	 * normalize it the same way.
	 */
	protected function extractIntList(mixed $parameters, string $key): array
	{
		if (!is_array($parameters) || !is_array($parameters[$key] ?? null))
		{
			return [];
		}

		return array_values(array_filter(array_map('intval', $parameters[$key])));
	}
}
