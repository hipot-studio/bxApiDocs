<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

/**
 * In-process registry of the server messages, by the key of the chat. The publishing service fills it right
 * before the trigger call and cleans it right after; the trigger action, a singleton of the process, reads
 * it during the call. A client call finds nothing here, so it publishes nothing.
 */
final class AiSiteChatNoticeRegistry
{
	/** @var array<string, AiSiteChatNotice> */
	private static array $notices = [];

	private function __construct()
	{
	}

	public static function put(string $entityId, AiSiteChatNotice $notice): void
	{
		self::$notices[$entityId] = $notice;
	}

	public static function find(string $entityId): ?AiSiteChatNotice
	{
		return self::$notices[$entityId] ?? null;
	}

	public static function remove(string $entityId): void
	{
		unset(self::$notices[$entityId]);
	}
}
