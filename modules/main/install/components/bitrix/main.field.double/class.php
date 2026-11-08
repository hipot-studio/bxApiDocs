<?php

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Component\BaseUfComponent;
use Bitrix\Main\UserField\Types\DoubleType;

/**
 * Class DoubleUfComponent
 */
class DoubleUfComponent extends BaseUfComponent
{
	/**
	 * Returns the decimal separator used in all field templates.
	 */
	public function getDecimalSeparator(): string
	{
		return '.';
	}

	/**
	 * $isValueFromForm marks a value that has just come from the request instead of the storage:
	 * an edit form is redrawn with what the user typed when the check of the form has failed
	 * (@see \CUserTypeManager::GetEditFormHTML(), `bVarsFromForm`).
	 */
	public function formatValue(
		$value,
		int $precision,
		?string $decimalSeparator = null,
		bool $isValueFromForm = false,
	): string
	{
		// false is the "no value" marker of CUserTypeManager::getEntities(): main.edit_form gets it
		// for an entity without a row in b_uts_*. An empty field must stay empty, not become a zero.
		// A non scalar (a nested array in the request) is not a value of the field either.
		if ($value === null || $value === false || $value === '' || !is_scalar($value))
		{
			return '';
		}

		if ($isValueFromForm)
		{
			$normalizedValue = str_replace([',', ' '], ['.', ''], (string)$value);
			if (
				!preg_match('/^[-+]?\d*[.,]?\d+?$/', $normalizedValue)
				&& !preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)$/', $normalizedValue)
			)
			{
				// Invalid input must stay unchanged so the next submit still fails validation.
				return (string)$value;
			}

			// Keep the user's valid input intact apart from the normalization performed on save.
			// In particular, do not turn a signed zero into zero or round it before the form is submitted.
			return $normalizedValue;
		}

		$precision = max(0, min(12, $precision));

		$value = (float)$value;
		if (!is_finite($value))
		{
			return number_format($value, $precision, '.', '');
		}

		return $this->toShortestFixedString(round($value, $precision), $precision);
	}

	/**
	 * Returns the shortest fixed-point notation (no exponent, at most $maxDecimals decimals)
	 * that is read back as exactly the same float. Unlike number_format() and sprintf('%.Nf')
	 * it does not print binary noise when the value has more than 15 significant digits.
	 */
	private function toShortestFixedString(float $value, int $maxDecimals): string
	{
		if ($value == 0)
		{
			return '0';
		}

		for ($decimals = 0; $decimals < $maxDecimals; $decimals++)
		{
			$result = sprintf("%.{$decimals}F", $value);
			if ((float)$result === $value)
			{
				return $result;
			}
		}

		return sprintf("%.{$maxDecimals}F", $value);
	}

	protected static function getUserTypeId(): string
	{
		return DoubleType::USER_TYPE_ID;
	}
}
