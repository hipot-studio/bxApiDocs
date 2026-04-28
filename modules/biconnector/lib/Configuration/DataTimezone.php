<?php

namespace Bitrix\BIConnector\Configuration;

use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class DataTimezone
{
	public const OPTION_NAME = 'data_timezone';

	public static function getTimezone(): string
	{
		return Option::get('biconnector', self::OPTION_NAME, '');
	}

	public static function getTimezoneOffset(): ?string
	{
		$timezone = self::getTimezone();
		if (empty($timezone))
		{
			return null;
		}

		$dtZone = new \DateTimeZone($timezone);
		$dateTime = new \DateTime('now', $dtZone);
		$offset = $dateTime->format('P');

		return $offset;
	}

	public static function setTimezone(string $timezone): void
	{
		Option::set('biconnector', self::OPTION_NAME, $timezone);

		Application::getInstance()->addBackgroundJob(static function (): void
		{
			CacheManager::getInstance()->clear();
		});
	}

	public static function getDefaultTimezone(): \DateTimeZone
	{
		return (new DateTime())->getTimeZone();
	}

	public static function getConfigTimezone(): \DateTimeZone
	{
		$timeZone = self::getTimezone();
		if (!empty($timeZone))
		{
			try
			{
				return new \DateTimeZone($timeZone);
			}
			catch (\Exception)
			{
			}
		}

		return self::getDefaultTimezone();
	}
}
