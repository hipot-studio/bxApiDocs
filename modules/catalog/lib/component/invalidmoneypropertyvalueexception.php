<?php

namespace Bitrix\Catalog\Component;

use Bitrix\Currency\Integration\IblockMoneyProperty;

final class InvalidMoneyPropertyValueException extends \RuntimeException
{
	private string $fieldName;

	public function __construct(string $fieldName, string $fieldTitle)
	{
		$errors = IblockMoneyProperty::checkFields([], ['VALUE' => 'invalid']);
		$message = $errors[0] ?? 'Invalid money property value';
		if ($fieldTitle !== '')
		{
			$message = $fieldTitle . ': ' . $message;
		}

		parent::__construct($message);
		$this->fieldName = $fieldName;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}
}
