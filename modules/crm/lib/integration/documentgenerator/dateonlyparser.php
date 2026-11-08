<?php

namespace Bitrix\Crm\Integration\DocumentGenerator;

use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\Main\Type;

final class DateOnlyParser
{
	/** @var string[] */
	private array $formats;

	public function __construct(string $siteFormat, string $templateFormat)
	{
		$this->formats = array_unique([$siteFormat, $templateFormat]);
	}

	public static function createForCurrentDocument(): self
	{
		return new self(
			Type\Date::getFormat(),
			Type\Date::getFormat(DataProviderManager::getInstance()->getCulture()),
		);
	}

	public function parse(mixed $value): ?Type\Date
	{
		if ($value instanceof Type\DateTime)
		{
			return new Type\Date($value->format('Y-m-d'), 'Y-m-d');
		}
		if ($value instanceof Type\Date)
		{
			return $value;
		}
		if (!is_scalar($value) || is_bool($value))
		{
			return null;
		}

		$string = trim((string)$value);
		if ($string === '')
		{
			return null;
		}

		try
		{
			return $this->parseString($string);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function parseString(string $string): ?Type\Date
	{
		foreach ($this->formats as $format)
		{
			$parts = date_parse_from_format($format, $string);
			if ($this->isStructureMatched($parts, $string))
			{
				return $parts['warning_count'] === 0 ? $this->createDate($parts) : null;
			}
		}

		$parts = date_parse($string);
		if (
			$parts['error_count'] === 0
			&& $parts['warning_count'] === 0
			&& !isset($parts['relative'])
			&& $this->hasCalendarDay($parts)
		)
		{
			return $this->createDate($parts);
		}

		return null;
	}

	private function isStructureMatched(array $parts, string $string): bool
	{
		if (!$this->hasCalendarDay($parts))
		{
			return false;
		}
		if ($parts['error_count'] === 0)
		{
			return true;
		}
		if ($parts['error_count'] !== 1 || !in_array('Trailing data', $parts['errors'], true))
		{
			return false;
		}

		return $this->isTimeOfDay(substr($string, array_key_first($parts['errors'])));
	}

	private function isTimeOfDay(string $tail): bool
	{
		$parts = date_parse($tail);

		return
			$parts['error_count'] === 0
			&& $parts['warning_count'] === 0
			&& $parts['hour'] !== false
			&& $parts['year'] === false
			&& $parts['month'] === false
			&& $parts['day'] === false
			&& !isset($parts['relative'])
		;
	}

	private function hasCalendarDay(array $parts): bool
	{
		return $parts['year'] !== false && $parts['month'] !== false && $parts['day'] !== false;
	}

	private function createDate(array $parts): ?Type\Date
	{
		if (!checkdate($parts['month'], $parts['day'], $parts['year']))
		{
			return null;
		}

		return new Type\Date(
			sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']),
			'Y-m-d',
		);
	}
}
