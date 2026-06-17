<?php

namespace Bitrix\Socialservices\OAuth;

use Bitrix\Main\Diag\JsonLinesFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

final class LoggerFactory
{
	private const OPTION_PREFIX = 'auth_log_level_';

	public function create(string $serviceId): LoggerInterface
	{
		if (!defined('LOG_FILENAME'))
		{
			return new NullLogger();
		}

		$optionName = self::OPTION_PREFIX . $serviceId;
		$level = trim((string)\CSocServAuth::GetOption($optionName));
		if ($level === '')
		{
			return new NullLogger();
		}

		if (!$this->isValidLevel($level))
		{
			trigger_error(
				sprintf('Invalid oauth auth log level "%s" for service "%s"', $level, $serviceId),
				E_USER_WARNING
			);

			return new NullLogger();
		}

		$logger = new AuthFileLogger(LOG_FILENAME, $serviceId);
		$logger
			->setLevel($level)
			->setFormatter(new JsonLinesFormatter())
		;

		return $logger;
	}

	private function isValidLevel(string $level): bool
	{
		static $validLevels = null;

		if ($validLevels === null)
		{
			$validLevels = [
				LogLevel::EMERGENCY,
				LogLevel::ALERT,
				LogLevel::CRITICAL,
				LogLevel::ERROR,
				LogLevel::WARNING,
				LogLevel::NOTICE,
				LogLevel::INFO,
				LogLevel::DEBUG,
			];
		}

		return in_array($level, $validLevels, true);
	}
}
