<?php

namespace Bitrix\Location\Infrastructure;

use Bitrix\Location\Entity\Source\Factory;
use Bitrix\Main\Config\Option;

/**
 * Class SourceCodePicker
 * @package Bitrix\Location\Infrastructure
 * @internal
 */
final class SourceCodePicker
{
	private const OPTION_NAME = 'location_default_source_code';

	/**
	 * @return string
	 */
	public static function getSourceCode(): string
	{
		return Option::get('location', self::OPTION_NAME, Factory::OSM_SOURCE_CODE);
	}

	/**
	 * @param string $sourceCode
	 */
	public static function setSourceCode(string $sourceCode): void
	{
		Option::set('location', self::OPTION_NAME, $sourceCode);
	}
}
