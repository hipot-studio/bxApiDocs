<?php


namespace Bitrix\Crm\Service\Display\Field;


use Bitrix\Crm\Format\Money;
use Bitrix\Crm\Service\Display\Options;

class NumberField extends StringField
{
	public const TYPE = 'number';

	protected function getFormattedValueForGrid($fieldValue, int $itemId, Options $displayOptions)
	{
		if ($this->isMultiple())
		{
			return $this->render($displayOptions, $itemId, $fieldValue);
		}

		return $this->renderSingleValue($fieldValue, $itemId, $displayOptions);
	}

	protected function renderSingleValue($fieldValue, int $itemId, Options $displayOptions): string
	{
		$value = parent::renderSingleValue($fieldValue, $itemId, $displayOptions);

		$valueType = $this->getDisplayParam(
			'VALUE_TYPE',
			\Bitrix\Crm\Field::VALUE_TYPE_PLAIN_TEXT,
		);

		if ($valueType === \Bitrix\Crm\Field::VALUE_TYPE_MONEY && $value !== '')
		{
			return Money::toNumberString($value, $this->resolveItemCurrencyId());
		}

		return $value;
	}

	protected function getFormattedValueForMobile($fieldValue, int $itemId, Options $displayOptions): array
	{
		$result = parent::getFormattedValueForMobile($fieldValue, $itemId, $displayOptions);

		$onlyInteger = true;
		if (is_array($result['value']))
		{
			foreach ($result['value'] as $value)
			{
				if ($this->isFloat($value))
				{
					$onlyInteger = false;
					break;
				}
			}
		}
		elseif ($this->isFloat($result['value']))
		{
			$onlyInteger = false;
		}

		if (!$onlyInteger)
		{
			$result['config']['precision'] = $this->resolveMobilePrecision();
		}

		return $result;
	}

	private function isFloat(string $value): bool
	{
		return (is_numeric($value) && strpos($value, '.') !== false);
	}

	/**
	 * Returns the currency code for the current item (read from the sibling field declared via SETTINGS['currencyFieldName']).
	 * Returns null when:
	 *  - the field has no declared currencyFieldName,
	 *  - the item context is not provided or is not a valid row (array / \ArrayAccess),
	 *  - the currency value in the row is empty.
	 */
	private function resolveItemCurrencyId(): ?string
	{
		$currencyFieldName = $this->getDisplayParam('currencyFieldName');
		if (!is_string($currencyFieldName) || $currencyFieldName === '')
		{
			return null;
		}

		$row = $this->getItemContext();
		if (empty($row) || (!is_array($row) && !($row instanceof \ArrayAccess)))
		{
			return null;
		}

		$currencyId = isset($row[$currencyFieldName]) ? (string)$row[$currencyFieldName] : '';

		return $currencyId !== '' ? $currencyId : null;
	}

	private function resolveMobilePrecision(): int
	{
		$valueType = $this->getDisplayParam(
			'VALUE_TYPE',
			\Bitrix\Crm\Field::VALUE_TYPE_PLAIN_TEXT,
		);

		if ($valueType !== \Bitrix\Crm\Field::VALUE_TYPE_MONEY)
		{
			return 2;
		}

		return Money::resolveDecimals($this->resolveItemCurrencyId());
	}

}
