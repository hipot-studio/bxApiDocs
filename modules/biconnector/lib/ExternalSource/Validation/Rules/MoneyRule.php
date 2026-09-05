<?php

namespace Bitrix\BIConnector\ExternalSource\Validation\Rules;

final class MoneyRule extends Rule
{
	private string $delimiter;

	public function __construct(string $delimiter)
	{
		parent::__construct();
		$this->delimiter = $delimiter;
	}

	public function validate($value): RuleValidationResult
	{
		$number = preg_replace('/[^-\d' . preg_quote($this->delimiter, '/') . ']/', '', $value);
		if ($this->delimiter !== '.')
		{
			$number = str_replace($this->delimiter, '.', $number);
		}

		$stringValue = (string)$number;

		if (trim((string)$value) === '')
		{
			return new RuleValidationResult();
		}

		// What is left after dropping currency signs and separators has to be a number:
		// otherwise the value would be imported as 0 without a word.
		if (!is_numeric($stringValue))
		{
			return new RuleValidationResult(false, str_replace('#VALUE#', $this->getValueForError($value), $this->invalidErrorTemplate));
		}

		return new RuleValidationResult();
	}
}
