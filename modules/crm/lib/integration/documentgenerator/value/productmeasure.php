<?php

namespace Bitrix\Crm\Integration\DocumentGenerator\Value;

use Bitrix\DocumentGenerator\Value;
use Bitrix\Main\Loader;

Loader::includeModule('documentgenerator');

/** @internal */
final class ProductMeasure extends Value
{
	public const WEIGHT = 'weight';
	public const LENGTH = 'length';

	private const WEIGHT_SOURCE_UNIT = 'G';
	private const WEIGHT_PRECISION = 3;
	private const LENGTH_PRECISION = 6;
	private const LENGTH_UNIT_IN_MILLIMETERS = [
		'MM' => 1.0,
		'CM' => 10.0,
		'DM' => 100.0,
		'M' => 1000.0,
		'KM' => 1000000.0,
		'IN' => 25.4,
		'FT' => 304.8,
		'YD' => 914.4,
	];

	private string $type;

	/**
	 * Creates a product measurement value.
	 *
	 * @param float|null $value
	 * @param string $type
	 */
	public function __construct(?float $value, string $type)
	{
		parent::__construct($value);
		$this->type = $type;
	}

	/**
	 * Formats the value using an optional measurement unit.
	 *
	 * @param mixed $modifier
	 * @return string
	 */
	public function toString($modifier = null): string
	{
		if ($this->value === null)
		{
			return '';
		}

		$options = $this->getOptions($modifier);
		$unit = mb_strtoupper((string)($options['unit'] ?? ''));
		$value = (float)$this->value;

		if ($this->type === self::WEIGHT)
		{
			$value = $this->convertWeight($value, $unit);
			$precision = defined('SALE_WEIGHT_PRECISION')
				? min((int)SALE_WEIGHT_PRECISION, self::WEIGHT_PRECISION)
				: self::WEIGHT_PRECISION
			;
			$value = roundEx($value, $precision);

			return $this->format($value, $precision);
		}

		if ($this->type === self::LENGTH && isset(self::LENGTH_UNIT_IN_MILLIMETERS[$unit]))
		{
			$value /= self::LENGTH_UNIT_IN_MILLIMETERS[$unit];
		}

		return $this->format($value, self::LENGTH_PRECISION);
	}

	private function convertWeight(float $value, string $unit): float
	{
		if ($unit === '' || !Loader::includeModule('sale'))
		{
			return $value;
		}

		$measurements = \CSaleMeasure::GetList('W');
		if (
			!is_array($measurements)
			|| !isset($measurements[self::WEIGHT_SOURCE_UNIT], $measurements[$unit])
		)
		{
			return $value;
		}

		$converted = \CSaleMeasure::Convert($value, self::WEIGHT_SOURCE_UNIT, $unit);

		return $converted === false ? $value : (float)$converted;
	}

	private function format(float $value, int $precision): string
	{
		$rounded = round($value, $precision);
		if ($rounded === 0.0)
		{
			return '0';
		}

		return rtrim(rtrim(number_format($rounded, $precision, '.', ''), '0'), '.');
	}
}
