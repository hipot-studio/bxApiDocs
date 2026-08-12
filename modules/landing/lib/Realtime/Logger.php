<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

use Bitrix\Main\Config\Option;

final class Logger
{
	private const OPTION_ENABLED = 'landing_ai_sites_log_enabled';
	private const FILE_NAME = 'log.txt';

	public static function write(string $stage, array $data = []): void
	{
		if (!self::isEnabled())
		{
			return;
		}

		$entry = [
			'time' => date('Y-m-d H:i:s'),
			'stage' => $stage,
			'data' => $data,
		];

		$encoded = json_encode(
			$entry,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if (!is_string($encoded))
		{
			$encoded = var_export($entry, true);
		}

		@file_put_contents(
			__DIR__ . '/' . self::FILE_NAME,
			$encoded . PHP_EOL,
			FILE_APPEND | LOCK_EX
		);
	}

	public static function isEnabled(): bool
	{
		try
		{
			return Option::get('landing', self::OPTION_ENABLED, 'N', '') === 'Y';
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public static function eventToArray(Event $event): array
	{
		return [
			'eventName' => $event->getEventName(),
			'entityType' => $event->getEntityType(),
			'entityId' => $event->getEntityId(),
			'action' => $event->getAction(),
			'source' => $event->getSource(),
			'initiatorUserId' => $event->getInitiatorUserId(),
			'scope' => $event->getScope(),
			'meta' => $event->getMeta(),
		];
	}
}
