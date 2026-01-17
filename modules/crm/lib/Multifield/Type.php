<?php

namespace Bitrix\Crm\Multifield;

/**
 * @internal Do not extend this class, it's still in active development phase.
 * This class is not covered by backwards compatibility
 */
abstract class Type
{
	public const ID = 'UNDEFINED';

	final public function getCaption(): string
	{
		$caption = (string)\CCrmFieldMulti::GetEntityTypeCaption(static::ID);
		if ($caption === static::ID)
		{
			// caption not found
			return '';
		}

		return $caption;
	}

	final public function getValueTypeCaption(string $valueType): string
	{
		$caption = \CCrmFieldMulti::GetEntityNameByComplex(
			static::ID . '_' . $valueType,
			false
		);

		if ($caption === false)
		{
			// caption not found
			return '';
		}

		return $caption;
	}

	public function formatValue(string $value): string
	{
		return $value;
	}
}
