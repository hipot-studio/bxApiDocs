<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Main\Config\Option;

class Log
{
	public const OPTION_ENABLED = 'landing_ai_sites_log_enabled';

	private const ENTRY_TYPE_TOOL_CALL = 'Tool call';

	private string $filePath;

	public function __construct()
	{
		$this->filePath = __DIR__ . '/log.txt';
	}

	public static function isEnabled(): bool
	{
		return Option::get('landing', self::OPTION_ENABLED, 'N', '') === 'Y';
	}

	public function add(string $message): void
	{
		if (self::isEnabled())
		{
			@file_put_contents($this->filePath, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
		}
	}

	public function addToolCall(
		string $toolName,
		string $toolClass,
		int $userId,
		int $effectiveUserId,
		array $arguments,
	): void
	{
		$this->add(implode(PHP_EOL, [
			'-----',
			'Date: ' . date('Y-m-d'),
			'Time: ' . date('H:i:s'),
			'Tool: ' . $toolName,
			'Tool class: ' . $toolClass,
			'User ID: ' . $userId,
			'Effective user ID: ' . $effectiveUserId,
			'Entry type: ' . self::ENTRY_TYPE_TOOL_CALL,
			'Data type: AI Assistant tool call',
			'Arguments:',
			$this->formatPayload($arguments),
			'',
		]));
	}

	private function formatPayload(mixed $payload): string
	{
		if (is_string($payload))
		{
			return $payload;
		}

		$encoded = json_encode(
			$payload,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return is_string($encoded) ? $encoded : var_export($payload, true);
	}
}
