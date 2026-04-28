<?php

namespace Bitrix\Main\Diag;

use Bitrix\Main\Config\Option;

class LoggerRegistry
{
	private const OPTION_MODULE = 'loggers';
	private const OPTION_PREFIX = 'logger_is_enabled_';

	private function getOptionName(string $loggerId): string
	{
		return self::OPTION_PREFIX . $loggerId;
	}

	/**
	 * Enable logger by id.
	 *
	 * @param string $loggerId
	 *
	 * @return void
	 */
	public function enable(string $loggerId): void
	{
		Option::set(self::OPTION_MODULE, $this->getOptionName($loggerId), 'Y');
	}

	/**
	 * Disable logger by id.
	 *
	 * @param string $loggerId
	 *
	 * @return void
	 */
	public function disable(string $loggerId): void
	{
		Option::delete(self::OPTION_MODULE, [
			'name' => $this->getOptionName($loggerId),
		]);
	}

	/**
	 * Checks if the logger is enabled by id.
	 *
	 * @param string $loggerId
	 *
	 * @return bool
	 */
	public function isEnabled(string $loggerId): bool
	{
		return Option::get(self::OPTION_MODULE, $this->getOptionName($loggerId)) === 'Y';
	}

	/**
	 * Gets the list of loggers that are enabled.
	 *
	 * @return string[]
	 */
	public function getEnabledLoggersIds(): iterable
	{
		$result = [];

		$allOptions = Option::getForModule(self::OPTION_MODULE);
		foreach ($allOptions as $key => $value)
		{
			if (str_starts_with($key, self::OPTION_PREFIX) && $value === 'Y')
			{
				$result[] = substr($key, strlen(self::OPTION_PREFIX));
			}
		}

		return $result;
	}
}
