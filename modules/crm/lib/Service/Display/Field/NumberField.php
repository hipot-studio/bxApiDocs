<?php


namespace Bitrix\Crm\Service\Display\Field;


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
		if ($valueType === \Bitrix\Crm\Field::VALUE_TYPE_MONEY)
		{
			// see \Bitrix\Crm\Format\Money::format() without module "currency"
			$value = number_format((float)$value, 2, '.', '');
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
			$result['config']['precision'] = 2;
		}

		return $result;
	}

	private function isFloat(string $value): bool
	{
		return (is_numeric($value) && strpos($value, '.') !== false);
	}

}
