<?php

namespace Bitrix\Location\Infrastructure;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\Application;
use Bitrix\Location\Repository\Format\DataCollection;

/**
 * Class CurrentFormatCode
 * @package Bitrix\Location\Entity\Format
 * Responsible for the setting and obtaining the format code.
 */
class FormatCode
{
	protected static $optionName = 'address_format_code';
	protected static $onChangedEventName = 'onCurrentFormatCodeChanged';

	public static function getCurrent(string $regionId = null, string $siteId = ''): string
	{
		return Option::get(
			'location',
			static::$optionName,
			static::getDefault($regionId),
			$siteId
		);
	}

	public static function setCurrent(string $formatCode, string $siteId = ''): void
	{
		$validCodes = array_keys(DataCollection::getAll('en'));
		if (!in_array($formatCode, $validCodes, true))
		{
			$formatCode = static::getDefault();
		}

		Option::set(
			'location',
			static::$optionName,
			$formatCode,
			$siteId
		);

		$eventFormatCode = static::toEventFormatCode($formatCode);

		$event = new Event('location', static::$onChangedEventName, ['formatCode' => $eventFormatCode]);
		$event->send();
	}

	private static function toEventFormatCode(string $formatCode): string
	{
		$aliases = [
			'DE' => 'EU',
			'BR' => 'EU',
		];

		return $aliases[$formatCode] ?? $formatCode;
	}

	/**
	 * @param string|null $regionId
	 * @return string
	 */
	public static function getDefault(string $regionId = null): string
	{
		$regionId = $regionId ?? static::getRegion();

		$map = [
			// CIS zones — Russian-style formatting
			'ru' => 'RU',
			'by' => 'RU',
			'ua' => 'RU',
			'ur' => 'RU',
			'kz' => 'RU_2',
			'uz' => 'UZ',
			// Reverse-order Asian formats (postal code and country first)
			'ja' => 'RU_2',
			'cn' => 'RU_2',
			'sc' => 'RU_2',
			// Anglo-American
			'en' => 'US',
			'co' => 'US',
			'id' => 'US',
			// British
			'uk' => 'UK',
			// European style — default for most cloud zones
			'eu' => 'EU',
			'de' => 'EU',
			'fr' => 'EU',
			'it' => 'EU',
			'pl' => 'EU',
			'la' => 'EU',
			'tr' => 'EU',
			'ar' => 'EU',
			'ae' => 'EU',
			'vn' => 'EU',
			'ms' => 'EU',
			'th' => 'EU',
			'hi' => 'EU',
			'in' => 'EU',
			'tc' => 'EU',
			// Latin American
			'br' => 'BR',
			'mx' => 'BR',
		];

		return $map[$regionId] ?? 'EU';
	}

	/**
	 * @return string
	 */
	private static function getRegion(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return $region ?? LANGUAGE_ID;
	}
}
