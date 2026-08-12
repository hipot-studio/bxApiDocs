<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

use Bitrix\Main\Config\Option;

/**
 * Manage generation errors and system messages
 */
class Log
{
	public const OPTION_ENABLED = 'landing_ai_sites_log_enabled';
	private const ENTRY_TYPE_REQUEST = 'AI request';
	private const ENTRY_TYPE_RESPONSE = 'AI response';
	private const ENTRY_TYPE_STRUCTURE_DIAGNOSTICS = 'Structure diagnostics';

	private int $generationId;
	private string $filePath;

	public function __construct(?int $generationId)
	{
		$this->generationId = (int)$generationId;
		$this->filePath = dirname(__DIR__) . '/log.txt';
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

	public function add(string $message): void
	{
		if (self::isEnabled())
		{
			@file_put_contents($this->filePath, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
		}
	}

	public function addRequest(int $stepId, string $dataType, mixed $markers): void
	{
		$this->addEntry(
			self::ENTRY_TYPE_REQUEST,
			$stepId,
			$dataType,
			'Prompt markers',
			$markers,
		);
	}

	public function addRequestPrompt(int $stepId, string $dataType, string $prompt): void
	{
		$this->addEntry(
			self::ENTRY_TYPE_REQUEST,
			$stepId,
			$dataType,
			'Prompt',
			$prompt,
		);
	}

	public function addResponse(int $stepId, string $dataType, mixed $response): void
	{
		$this->addEntry(
			self::ENTRY_TYPE_RESPONSE,
			$stepId,
			$dataType,
			'AI response',
			$response,
		);
	}

	public function addStructureDiagnostics(int $stepId, array $diagnostics): void
	{
		$this->add(implode(PHP_EOL, [
			'-----',
			'Date: ' . date('Y-m-d'),
			'Time: ' . date('H:i:s'),
			'Generation ID: ' . $this->generationId,
			'Entry type: ' . self::ENTRY_TYPE_STRUCTURE_DIAGNOSTICS,
			'Step: ' . $stepId,
			'Data type: AI response: site structure diagnostics',
			'Passed:',
			$this->formatPayload($diagnostics['passed'] ?? []),
			'Failed:',
			$this->formatPayload($diagnostics['failed'] ?? []),
			'Warnings:',
			$this->formatPayload($diagnostics['warnings'] ?? []),
			'Summary:',
			$this->formatPayload($diagnostics['summary'] ?? []),
			'',
		]));
	}

	private function addEntry(string $entryType, int $stepId, string $dataType, string $payloadTitle, mixed $payload): void
	{
		$this->add(implode(PHP_EOL, [
			'-----',
			'Date: ' . date('Y-m-d'),
			'Time: ' . date('H:i:s'),
			'Generation ID: ' . $this->generationId,
			'Entry type: ' . $entryType,
			'Step: ' . $stepId,
			'Data type: ' . $dataType,
			$payloadTitle . ':',
			$this->formatPayload($payload),
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
