<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

use Bitrix\Crm\V2\Internal\Entity\PaySystem\ResolvedHandler;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class HandlerGuard
{
	public const ERROR_NOT_ALLOWED = 'CRM_PAYSYSTEM_HANDLER_NOT_ALLOWED';

	private readonly string $documentRoot;

	public function __construct(
		private readonly HandlerCatalog $catalog = new HandlerCatalog(),
		?string $documentRoot = null,
	)
	{
		$this->documentRoot = rtrim($documentRoot ?? Application::getDocumentRoot(), '/\\');
	}

	public function resolve(array $fields, string $purpose, array $context = []): Result
	{
		$actionFile = $fields['ACTION_FILE'] ?? null;
		if (!is_string($actionFile) || $actionFile === '')
		{
			return $this->deny();
		}
		$entry = $this->catalog->find($actionFile, $purpose, $this->documentRoot);
		if ($entry === null)
		{
			return $this->deny();
		}
		if ($purpose === HandlerCatalog::PURPOSE_LEGACY_PRINT)
		{
			if (!\Bitrix\Main\Loader::includeModule('sale'))
			{
				return $this->deny();
			}
			$rest = \Bitrix\Sale\Internals\PaySystemRestHandlersTable::getRow([
				'select' => ['ID'],
				'filter' => ['@CODE' => [$actionFile, $entry['code']]],
			]);
			if ($rest)
			{
				return $this->deny();
			}
		}

		$root = realpath($this->documentRoot . $entry['root']);
		$directory = realpath($this->documentRoot . $entry['directory']);
		if (
			$root === false
			|| $directory === false
			|| !is_dir($root)
			|| !is_dir($directory)
			|| !$this->isWithin($directory, $root)
		)
		{
			return $this->deny();
		}

		$files = ['directory' => $directory];
		foreach ($entry['files'] as $role => $relativePath)
		{
			$physicalPath = realpath($this->documentRoot . $entry['directory'] . '/' . $relativePath);
			if (
				$physicalPath === false
				|| !is_file($physicalPath)
				|| !is_readable($physicalPath)
				|| !$this->isWithin($physicalPath, $root)
			)
			{
				return $this->deny();
			}

			$files[$role] = $physicalPath;
		}

		$paymentId = $context['paymentId'] ?? null;
		$paySystemId = $context['paySystemId'] ?? null;
		if (
			($paymentId !== null && (!is_int($paymentId) || $paymentId <= 0))
			|| ($paySystemId !== null && (!is_int($paySystemId) || $paySystemId <= 0))
		)
		{
			return $this->deny();
		}

		$context = array_merge($context, [
			'documentRoot' => $this->documentRoot,
			'handlerRoot' => $root,
		]);
		try
		{
			$handler = new ResolvedHandler(
				fields: $fields,
				code: $entry['code'],
				purpose: $purpose,
				serviceAction: $entry['serviceAction'],
				files: $files,
				className: $entry['className'],
				handlerType: $entry['handlerType'],
				context: $context,
				paymentId: $paymentId,
				paySystemId: $paySystemId,
			);
		}
		catch (\InvalidArgumentException)
		{
			return $this->deny();
		}

		return (new Result())->setData(['handler' => $handler]);
	}

	private function isWithin(string $path, string $root): bool
	{
		$path = str_replace('\\', '/', $path);
		$root = rtrim(str_replace('\\', '/', $root), '/');

		return str_starts_with($path, $root . '/');
	}

	private function deny(): Result
	{
		return (new Result())->addError(new Error('Payment system handler is not allowed.', self::ERROR_NOT_ALLOWED));
	}
}
