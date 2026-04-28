<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Ldap\Settings;
use Bitrix\Main\ModuleManager;
use Psr\Log\LoggerInterface;
use Bitrix\Main\Diag;

class Logger
{
	private string $traceId;

	private ?LoggerInterface $writer = null;

	public function __construct(
		private readonly Settings $settings,
	)
	{}

	public function start(string $prefix = 'LdapSync'): void
	{
		$this->traceId = uniqid($prefix);

		$text = sprintf(
			'[%s] sync session started',
			$this->traceId,
		);

		$this->write($text);
	}

	public function collectDebugInfo(): void
	{
		$text = sprintf(
			'[%s] php version: %s. main version: %s. ldap version: %s',
			$this->traceId,
			PHP_VERSION,
			ModuleManager::getVersion('main'),
			ModuleManager::getVersion('ldap'),
		);

		$this->write($text);
	}

	public function log(string $message): void
	{
		$text = sprintf(
			'[%s] %s',
			$this->traceId,
			$message,
		);

		$this->write($text);
	}

	public function stop(): void
	{
		$text = sprintf(
			'[%s] sync finished. Memory usage: %s. Memory peak usage: %s',
			$this->traceId,
			memory_get_usage(true),
			memory_get_peak_usage(true),
		);

		$this->write($text);
	}

	protected function write(string $text): void
	{
		if (!$this->writer)
		{
			$path = $this->settings->getSyncLoggerFilePath();
			if (empty($path))
			{
				return;
			}

			$this->writer = new Diag\FileLogger($path, 0);
			$this->writer->setFormatter(new Diag\LogFormatter());
		}

		$message = "Host: {host}\n"
			. "Date: {date}\n"
			. "{message}\n"
			. "{delimiter}\n";

		$this->writer->debug($message, [
			'message' => $text,
		]);
	}
}
