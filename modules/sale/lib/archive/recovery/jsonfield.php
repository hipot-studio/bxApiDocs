<?php
namespace Bitrix\Sale\Archive\Recovery;

use Bitrix\Main;

/**
 * @package Bitrix\Sale\Archive\Recovery
 */
class JsonField extends PackedField
{
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/** @var Main\Context\Culture[]|null */
	private static ?array $cultures = null;

	private ?array $dateFormats = null;

	public static function pack(array $fields): string
	{
		return Main\Web\Json::encode(self::formatDates($fields));
	}

	private static function formatDates(array $fields): array
	{
		foreach ($fields as &$field)
		{
			if (is_array($field))
			{
				$field = self::formatDates($field);
			}
			elseif ($field instanceof Main\Type\Date)
			{
				$field = $field->format(self::DATE_FORMAT);
			}
		}

		return $fields;
	}

	public function tryUnpack()
	{
		$result = new Main\Result();
		try
		{
			$value = Main\Web\Json::decode($this->packedValue);
			if (!$value)
			{
				$result->addError(new Main\Error('Unavailable value for unpacking'));
			}
		}
		catch (\Exception $e)
		{
			$result->addError(new Main\Error('Unavailable value for unpacking'));
		}

		return $result;
	}

	public function unpack()
	{
		try
		{
			$unpacked = Main\Web\Json::decode($this->packedValue);
			if (!is_array($unpacked))
				return null;

			return $this->formatResult($unpacked);
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	private function formatResult(array $fields)
	{
		foreach ($fields as &$field)
		{
			if (is_array($field))
			{
				$field = $this->formatResult($field);
			}
			elseif (is_string($field))
			{
				$field = $this->parseDate($field) ?? $field;
			}
		}

		return $fields;
	}

	private function parseDate(string $value): ?Main\Type\DateTime
	{
		if (preg_match('/^\d/', $value) !== 1)
		{
			return null;
		}

		foreach ($this->getDateFormats() as $format)
		{
			$parsed = date_parse_from_format($format, $value);
			if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0)
			{
				return new Main\Type\DateTime($value, $format);
			}
		}

		return null;
	}

	private function getDateFormats(): array
	{
		$this->dateFormats ??= $this->collectDateFormats();

		return $this->dateFormats;
	}

	/**
	 * Archives packed before DATE_FORMAT keep dates in the culture of the archiving hit.
	 */
	private function collectDateFormats(): array
	{
		$formats = [self::DATE_FORMAT];

		$cultures = self::getCultures();
		$currentCulture = Main\Context::getCurrent()?->getCulture();
		if ($currentCulture)
		{
			array_unshift($cultures, $currentCulture);
		}

		foreach ($cultures as $culture)
		{
			$formats[] = Main\Type\DateTime::getFormat($culture);
			$formats[] = Main\Type\Date::getFormat($culture);
		}

		return array_values(array_unique($formats));
	}

	private static function getCultures(): array
	{
		if (self::$cultures === null)
		{
			self::$cultures = Main\Localization\CultureTable::getList([
				'select' => ['ID', 'FORMAT_DATE', 'FORMAT_DATETIME'],
				'order' => ['ID' => 'ASC'],
				'cache' => ['ttl' => 86400],
			])->fetchCollection()->getAll();
		}

		return self::$cultures;
	}
}
