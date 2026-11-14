<?php

declare(strict_types=1);

namespace Bitrix\AI\Cloud\PromptDb;

use Bitrix\AI\Synchronization\Enum\SyncMode;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Closure;

final class PromptDbProxyUpdater
{
	private const TTL_HOURS = 3;

	private const ERROR_AUTH = 'auth';
	private const ERROR_NETWORK = 'network';
	private const ERROR_VALIDATION = 'validation';
	private const ERROR_STORAGE = 'storage';
	private const ERROR_CONFIG = 'config';

	/**
	 * @param Closure(string, string, SyncMode): void $refreshFromJson
	 * @param Closure(): void $refreshFromStaticHost
	 * @param Closure(int): void $makeExpired
	 * @param Closure(string): void $sendInfo
	 */
	public function __construct(
		private readonly PromptDbClient $client,
		private readonly int $currentVersion,
		private readonly bool $needFull,
		private readonly Closure $refreshFromJson,
		private readonly Closure $refreshFromStaticHost,
		private readonly Closure $makeExpired,
		private readonly Closure $sendInfo,
	)
	{
	}

	public function refresh(): void
	{
		$updateInfoResult = $this->client->getUpdateInfo($this->currentVersion, $this->needFull);
		if (!$updateInfoResult->isSuccess())
		{
			$this->handleFailure($updateInfoResult, 'getUpdateInfo');

			return;
		}

		$updateInfo = $updateInfoResult->getData();
		$mode = (string)($updateInfo['mode'] ?? '');
		$sourceVersion = isset($updateInfo['version']) ? (int)$updateInfo['version'] : null;

		if ($mode === 'none')
		{
			$this->sendPromptDbInfo(
				'aiproxy update is not required.',
				new PromptDbLogContext(
					boxVersion: $this->currentVersion,
					sourceVersion: $sourceVersion,
					mode: 'none',
				),
			);
			($this->makeExpired)(self::TTL_HOURS);

			return;
		}

		if (!in_array($mode, ['diff', 'full'], true))
		{
			$this->sendPromptDbInfo(
				'aiproxy getUpdateInfo returned unsupported mode. Fallback to static host.',
				new PromptDbLogContext(
					boxVersion: $this->currentVersion,
					sourceVersion: $sourceVersion,
					mode: $mode,
					fallbackReason: 'unsupported_mode',
					errorType: self::ERROR_VALIDATION,
					unsupportedMode: $mode,
				),
			);
			($this->refreshFromStaticHost)();

			return;
		}

		$downloadResult = $this->client->download($this->currentVersion, $this->needFull, $mode);
		if (!$downloadResult->isSuccess())
		{
			$this->handleFailure($downloadResult, 'download');

			return;
		}

		$body = $downloadResult->getData()[PromptDbClient::DATA_BODY] ?? null;
		if (!is_string($body) || $body === '')
		{
			$this->sendPromptDbInfo(
				'aiproxy download returned empty file body. Fallback to static host.',
				new PromptDbLogContext(
					boxVersion: $this->currentVersion,
					sourceVersion: $sourceVersion,
					mode: $mode,
					fileSize: 0,
					fallbackReason: 'empty_body',
					errorType: self::ERROR_NETWORK,
				),
			);
			($this->refreshFromStaticHost)();

			return;
		}

		$this->sendPromptDbInfo(
			'aiproxy download accepted.',
			new PromptDbLogContext(
				boxVersion: $this->currentVersion,
				sourceVersion: $sourceVersion,
				mode: $mode,
				fileSize: strlen($body),
			),
		);

		($this->refreshFromJson)(
			$body,
			'',
			$mode === 'diff' ? SyncMode::Partitional : SyncMode::Standard,
		);
	}

	private function handleFailure(Result $result, string $action): void
	{
		$formattedErrors = $this->formatErrors($result);

		if ($this->isAuthorizationError($result))
		{
			$this->sendPromptDbInfo(
				"aiproxy {$action} authorization failed. {$formattedErrors}",
				new PromptDbLogContext(
					boxVersion: $this->currentVersion,
					fallbackReason: 'authorization_failed',
					errorType: self::ERROR_AUTH,
				),
			);
			($this->makeExpired)(self::TTL_HOURS);

			return;
		}

		$this->sendPromptDbInfo(
			"aiproxy {$action} failed. {$formattedErrors}. Fallback to static host.",
			new PromptDbLogContext(
				boxVersion: $this->currentVersion,
				fallbackReason: $action . '_failed',
				errorType: $this->classifyErrors($result),
			),
		);
		($this->refreshFromStaticHost)();
	}

	private function isAuthorizationError(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ((string)$error->getCode() === PromptDbClient::ERROR_AUTH_DENIED)
			{
				return true;
			}
		}

		return false;
	}

	private function formatErrors(Result $result): string
	{
		$messages = [];
		foreach ($result->getErrors() as $error)
		{
			$messages[] = '[' . $error->getCode() . '] ' . $error->getMessage();
		}

		return implode('; ', $messages);
	}

	private function classifyErrors(Result $result): string
	{
		foreach ($result->getErrors() as $error)
		{
			$code = mb_strtolower((string)$error->getCode());
			if ($code === mb_strtolower(PromptDbClient::ERROR_AUTH_DENIED))
			{
				return self::ERROR_AUTH;
			}

			$message = mb_strtolower($error->getMessage());
			foreach (['configuration', 'not configured', 'server_not_configured'] as $needle)
			{
				if (str_contains($message, $needle) || str_contains($code, $needle))
				{
					return self::ERROR_CONFIG;
				}
			}

			foreach (['malformed', 'wrong_server_response', 'unexpected_wrapped_response', 'invalid'] as $needle)
			{
				if (str_contains($message, $needle) || str_contains($code, $needle))
				{
					return self::ERROR_VALIDATION;
				}
			}

			foreach (['storage', 'cannot be read', 'cannot be written', 'cannot be created', 'cannot be published'] as $needle)
			{
				if (str_contains($message, $needle) || str_contains($code, $needle))
				{
					return self::ERROR_STORAGE;
				}
			}
		}

		return self::ERROR_NETWORK;
	}

	private function sendPromptDbInfo(string $message, ?PromptDbLogContext $context = null): void
	{
		$contextData = $context?->toArray() ?? [];
		if (!empty($contextData))
		{
			$message .= ' ' . $this->encodePromptDbLogContext($contextData);
		}

		($this->sendInfo)($message);
	}

	private function encodePromptDbLogContext(array $context): string
	{
		try
		{
			return Json::encode($context);
		}
		catch (\Throwable)
		{
			return '{}';
		}
	}
}
