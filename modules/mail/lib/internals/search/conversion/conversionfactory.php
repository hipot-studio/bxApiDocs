<?php

namespace Bitrix\Mail\Internals\Search\Conversion;

use Bitrix\Mail\Internals\Search\Conversion\Converters\UserNameConverter;
use Bitrix\Mail\Internals\Search\FieldDictionary;

class ConversionFactory
{
	private static array $converters = [];

	public function getConverterByField(string $fieldName): ConverterInterface
	{
		$converterClass = match ($fieldName)
		{
			FieldDictionary::FIELD_USER_ID => UserNameConverter::class,
			default => BaseConverter::class,
		};

		return $this->getConverter($converterClass);
	}

	private function getConverter(string $converterClass): ConverterInterface
	{
		if (!isset(self::$converters[$converterClass]))
		{
			self::$converters[$converterClass] = new $converterClass();
		}

		return self::$converters[$converterClass];
	}
}
